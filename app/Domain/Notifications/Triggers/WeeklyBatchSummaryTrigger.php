<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Models\Batch;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 9 - Weekly batch summary.
 *
 * Recipient: the internal users running the batch. Timing: Monday morning.
 * Source: a roll-up of the batch.
 *
 * The only trigger addressed to staff rather than a business, which is why
 * notification_dispatches has nullable customer_id and enrollment_id.
 *
 * Recipients are resolved from who actually conducts the batch's sessions -
 * the one recorded link between a batch and a member of staff. There is no
 * Consultant role and no owner column on batches, and inventing either to
 * address this reminder would be inventing an organisational rule the SOW
 * does not give. A batch nobody is recorded as running produces no summary.
 */
class WeeklyBatchSummaryTrigger implements NotificationTrigger
{
    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'weekly_batch_summary';
    }

    public function unresolvedDependency(): ?string
    {
        return null;
    }

    /**
     * @return iterable<int, NotificationCandidate>
     */
    public function candidates(CarbonImmutable $asOf): iterable
    {
        if ($asOf->dayOfWeekIso !== (int) config('notifications.timing.weekly_summary_day', 1)) {
            return;
        }

        $batches = Batch::query()
            ->whereNull('archived_at')
            ->where('status', '!=', 'completed')
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            foreach ($this->recipients->internalUsersForBatch($batch) as $user) {
                yield new NotificationCandidate(
                    triggerKey: $this->key(),
                    recipient: $user,
                    // A batch roll-up spans many businesses, so neither field
                    // applies. Naming one of them would be a lie about scope.
                    customerId: null,
                    enrollmentId: null,
                    subject: $batch,
                    scheduledFor: $asOf,
                    // One per ISO week, so a Monday sweep that runs twice
                    // still sends once.
                    period: $asOf->format('o-\WW'),
                );
            }
        }
    }
}
