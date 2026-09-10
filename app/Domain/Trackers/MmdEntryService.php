<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Enums\AuditAction;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recording and amending the daily business figures.
 *
 * [B1 CLIENT DECISION - MMD GRAIN]
 *
 * Whether one business files ONE row a day, or its contributors file their
 * own, is unresolved. This service is deliberately written so that neither
 * answer is assumed:
 *
 *   - record() does NOT refuse a second entry for the same day. Under answer B
 *     that is normal; under answer A the database will refuse it once the
 *     unique key is added, and this service will not have to change.
 *   - Nothing here invents a contributor, a function or a role dimension.
 *   - Every read is an AGGREGATE over a date range, which returns the right
 *     answer under both grains: one row summed is that row.
 *
 * entriesFor() exists so a caller that needs to know whether a day already has
 * figures can ask, without this service deciding what "the entry for a day"
 * means.
 *
 * OPTIMISTIC LOCKING, NOT PESSIMISTIC. Two actors may plausibly write one row:
 * the owner through an enter_mmd grant while staff key the same day from a
 * paper sheet. lockForUpdate() is a no-op on SQLite, so a pessimistic lock
 * would pass every test here and fail in production; a conditional UPDATE on
 * lock_version behaves identically on both engines.
 */
class MmdEntryService
{
    public function __construct(
        private readonly TrackerOwnership $ownership,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, int|float|null>  $figures  keyed by MmdEntry::METRICS
     */
    public function record(
        Customer $customer,
        DateTimeInterface|string $entryDate,
        array $figures = [],
        ?Enrollment $enrollment = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): MmdEntry {
        $figures = $this->validated($figures);
        $this->ownership->assertEnrollmentBelongsToCustomer($enrollment, $customer);

        if ($grant !== null && $enrollment !== null) {
            $this->ownership->assertGrantMatchesEnrollment($grant, $enrollment);
        }

        return DB::transaction(function () use ($customer, $entryDate, $figures, $enrollment, $actor, $grant): MmdEntry {
            $entry = new MmdEntry;
            $entry->forceFill(array_merge($figures, [
                'customer_id' => $customer->getKey(),
                // Attribution, not ownership. Business data outlives a run.
                'enrollment_id' => $enrollment?->getKey(),
                'entry_date' => $entryDate,
                'recorded_at' => now(),
                'lock_version' => 0,
                'source' => $this->ownership->resolveSource($actor, $grant),
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ]))->save();

            return $entry->fresh();
        });
    }

    /**
     * Amend an entry, refusing the write if someone else has changed it since
     * the caller read it.
     *
     * $expectedVersion is the lock_version the caller saw. The UPDATE carries
     * it in its WHERE clause, so a concurrent writer's increment makes this
     * update match zero rows - and the caller is told, rather than silently
     * overwriting a figure it never saw.
     *
     * @param  array<string, int|float|null>  $figures
     */
    public function amend(
        MmdEntry $entry,
        array $figures,
        int $expectedVersion,
        User $actor,
        ?string $reason = null,
    ): MmdEntry {
        $figures = $this->validated($figures);

        return DB::transaction(function () use ($entry, $figures, $expectedVersion, $actor, $reason): MmdEntry {
            $before = array_intersect_key($entry->only(MmdEntry::METRICS), $figures);

            $applied = MmdEntry::query()
                ->whereKey($entry->getKey())
                ->where('lock_version', $expectedVersion)
                ->update(array_merge($figures, [
                    'lock_version' => DB::raw('lock_version + 1'),
                    'amended_at' => now(),
                    'updated_at' => now(),
                ]));

            if ($applied !== 1) {
                throw new RuntimeException(sprintf(
                    'MMD entry %d has changed since it was read (expected version %d). Re-read '
                    .'the figures and amend again - overwriting a value nobody saw would change a '
                    .'target-versus-actual conclusion silently.',
                    $entry->getKey(),
                    $expectedVersion,
                ));
            }

            $fresh = $entry->fresh();

            $this->audit->log(
                AuditAction::MmdEntryAmended,
                $fresh,
                $before,
                array_merge($figures, ['reason' => $reason]),
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * Every entry a business filed for one day.
     *
     * Returns a COLLECTION, not a single row, precisely because the grain is
     * unresolved: under answer A there will be one, under answer B several,
     * and this signature is correct either way.
     *
     * @return Collection<int, MmdEntry>
     */
    public function entriesFor(Customer $customer, DateTimeInterface|string $entryDate): Collection
    {
        return MmdEntry::query()
            ->where('customer_id', $customer->getKey())
            ->whereDate('entry_date', CarbonImmutable::parse($entryDate)->toDateString())
            ->orderBy('id')
            ->get();
    }

    /**
     * Did this business file anything at all for this day?
     *
     * What notification triggers 5 and 6 need, phrased so that it does not
     * depend on the grain.
     */
    public function hasEntryFor(Customer $customer, DateTimeInterface|string $entryDate): bool
    {
        return MmdEntry::query()
            ->where('customer_id', $customer->getKey())
            ->whereDate('entry_date', CarbonImmutable::parse($entryDate)->toDateString())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, int|float|null>
     */
    private function validated(array $figures): array
    {
        foreach (array_keys($figures) as $metric) {
            if (! in_array($metric, MmdEntry::METRICS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown MMD metric [%s]. The measure set is fixed: %s.',
                    $metric,
                    implode(', ', MmdEntry::METRICS),
                ));
            }
        }

        foreach ($figures as $metric => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("MMD metric [{$metric}] cannot be negative.");
            }
        }

        return $figures;
    }
}
