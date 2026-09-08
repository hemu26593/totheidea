<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Models\AccessGrant;
use App\Models\Enrollment;
use App\Models\TimeGridEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Quarterly strategic allocation - Q1, Q2, Q3, Q4.
 *
 * NOT A DAILY TASK LIST. There is no date here, no status, no time slot and no
 * carry-forward, because those belong to the Day Plan. The two instruments
 * answer different questions - "where should the year's effort go?" against
 * "what am I doing today?" - and keeping them apart is what stops the
 * quarterly plan quietly becoming a to-do list.
 *
 * NO ALLOCATION FORMULA. This records planned and actual hours as entered.
 * Nothing here scores a quarter, ranks activities, or derives a
 * recommendation: no such rule has been specified, and inventing one would put
 * a number in front of a client that nobody agreed.
 */
class TimeGridService
{
    public function __construct(
        private readonly TrackerOwnership $ownership,
        private readonly AuditLogger $audit,
    ) {}

    public function record(
        Enrollment $enrollment,
        int $year,
        int $quarter,
        string $activity,
        ?float $plannedHours = null,
        ?float $actualHours = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): TimeGridEntry {
        $this->assertQuarterIsValid($quarter);
        $this->assertHoursAreNotNegative($plannedHours, $actualHours);
        $this->ownership->assertGrantMatchesEnrollment($grant, $enrollment);

        return DB::transaction(function () use (
            $enrollment, $year, $quarter, $activity, $plannedHours, $actualHours, $actor, $grant
        ): TimeGridEntry {
            $entry = new TimeGridEntry;
            $entry->forceFill([
                'enrollment_id' => $enrollment->getKey(),
                'year' => $year,
                'quarter' => $quarter,
                'activity' => $activity,
                'planned_hours' => $plannedHours,
                'actual_hours' => $actualHours,
                'source' => $this->ownership->resolveSource($actor, $grant),
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            return $entry->fresh();
        });
    }

    /**
     * Amend an entry - typically to add actuals after the quarter has run.
     *
     * Audited, because the planned figure a quarter was reviewed against must
     * survive being revised.
     */
    public function amend(
        TimeGridEntry $entry,
        ?float $plannedHours = null,
        ?float $actualHours = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): TimeGridEntry {
        $this->assertHoursAreNotNegative($plannedHours, $actualHours);
        $this->ownership->assertGrantMatchesEnrollment($grant, $entry->enrollment);

        return DB::transaction(function () use ($entry, $plannedHours, $actualHours, $actor, $grant): TimeGridEntry {
            $before = [
                'planned_hours' => $entry->planned_hours,
                'actual_hours' => $entry->actual_hours,
            ];

            $entry->forceFill([
                'planned_hours' => $plannedHours ?? $entry->planned_hours,
                'actual_hours' => $actualHours ?? $entry->actual_hours,
            ])->save();

            $fresh = $entry->fresh();

            $this->audit->log(
                AuditAction::TimeGridAmended,
                $fresh,
                $before,
                ['planned_hours' => $fresh->planned_hours, 'actual_hours' => $fresh->actual_hours],
                $actor,
                $grant !== null ? ActorSource::ExternalGrant : null,
                $grant,
            );

            return $fresh;
        });
    }

    /**
     * One year's grid for one participant, in quarter order.
     *
     * @return Collection<int, TimeGridEntry>
     */
    public function gridFor(Enrollment $enrollment, int $year): Collection
    {
        return TimeGridEntry::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('year', $year)
            ->orderBy('quarter')
            ->orderBy('activity')
            ->get();
    }

    /**
     * One quarter's entries.
     *
     * @return Collection<int, TimeGridEntry>
     */
    public function quarter(Enrollment $enrollment, int $year, int $quarter): Collection
    {
        $this->assertQuarterIsValid($quarter);

        return TimeGridEntry::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->orderBy('activity')
            ->get();
    }

    private function assertQuarterIsValid(int $quarter): void
    {
        if (! in_array($quarter, TimeGridEntry::QUARTERS, true)) {
            throw new InvalidArgumentException(
                "Quarter must be one of Q1, Q2, Q3 or Q4; got [{$quarter}]."
            );
        }
    }

    private function assertHoursAreNotNegative(?float $planned, ?float $actual): void
    {
        foreach (['planned_hours' => $planned, 'actual_hours' => $actual] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("{$name} cannot be negative.");
            }
        }
    }
}
