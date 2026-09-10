<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Domain\Trackers\MmdEntryService;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\MmdEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * TRIGGER 6 - Dashboard missed 3 days running.
 *
 * Recipient: the internal users running the batch. Timing: immediately.
 * Source: a GAP in mmd_entries.
 *
 * Unblocked by Phase 6, which built mmd_entries. Phase 5 deferred this trigger
 * on the absence of that table alone: the three-day window is stated by the
 * SOW and lives in config, so once there are rows to look at, a gap is a gap.
 *
 * NOT BLOCKED BY [L6]. That decision - whether an entry is EXPECTED daily or
 * only on Tuesday, Thursday and Saturday - governs trigger 5, which asks
 * whether today's entry is missing. This trigger asks a different question: has
 * the dashboard fallen silent for three days running? A three-day silence is a
 * silence under either cadence.
 *
 * [B1 CLIENT DECISION] does not reach here either: presence is asked with an
 * EXISTS, which is correct whether a day holds one row or several.
 *
 * A business that has never recorded anything is not "missing" days - the
 * dashboard has not gone live for them yet, and a gap presupposes a prior
 * presence.
 */
class DashboardMissedThreeDaysTrigger implements NotificationTrigger
{
    use ConcernsEnrolledParticipants;

    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly MmdEntryService $entries,
    ) {}

    public function key(): string
    {
        return 'dashboard_missed_three_days';
    }

    public function unresolvedDependency(): ?string
    {
        return Schema::hasTable('mmd_entries')
            ? null
            : 'mmd_entries does not exist yet (table 30, Phase 6).';
    }

    /**
     * @return iterable<int, NotificationCandidate>
     */
    public function candidates(CarbonImmutable $asOf): iterable
    {
        $window = max(1, (int) config('notifications.timing.dashboard_missed_days', 3));

        foreach (Batch::query()->whereNull('archived_at')->orderBy('id')->get() as $batch) {
            $consultants = $this->recipients->internalUsersForBatch($batch);

            if ($consultants->isEmpty()) {
                continue;
            }

            foreach ($this->activeEnrollmentsInBatch((int) $batch->getKey()) as $enrollment) {
                $customer = $enrollment->customer()->first();

                if ($customer === null || ! $this->hasEverRecorded($customer->getKey())) {
                    continue;
                }

                if (! $this->hasFallenSilent($customer, $asOf, $window)) {
                    continue;
                }

                foreach ($consultants as $consultant) {
                    yield new NotificationCandidate(
                        triggerKey: $this->key(),
                        recipient: $consultant,
                        customerId: (int) $customer->getKey(),
                        enrollmentId: (int) $enrollment->getKey(),
                        subject: $enrollment,
                        scheduledFor: $asOf,
                        // Daily while the silence continues - the same
                        // treatment as trigger 2, and the reason the dedupe
                        // key carries a date at all.
                        period: $asOf->toDateString(),
                    );
                }
            }
        }
    }

    /**
     * The last $window days, ending yesterday, with nothing recorded on any of
     * them.
     */
    private function hasFallenSilent(Customer $customer, CarbonImmutable $asOf, int $window): bool
    {
        for ($daysBack = 1; $daysBack <= $window; $daysBack++) {
            if ($this->entries->hasEntryFor($customer, $asOf->subDays($daysBack)->toDateString())) {
                return false;
            }
        }

        return true;
    }

    private function hasEverRecorded(int $customerId): bool
    {
        return MmdEntry::query()->where('customer_id', $customerId)->exists();
    }
}
