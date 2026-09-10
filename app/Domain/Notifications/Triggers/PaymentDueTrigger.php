<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Models\Enrollment;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 7 - Payment due date crossed.
 *
 * Recipient: participant. Timing: on the date, then every 2 days.
 * Source: enrollments.payment_due_date.
 *
 * [S3] - the column is nullable because whether the platform tracks payment
 * dates at all is an open client decision. This trigger reads it and nothing
 * else: an enrolment with no payment_due_date simply never qualifies, so if
 * the answer to S3 is "no", the trigger is inert without any code change. No
 * payment status, invoice or gateway is involved, and none is inferred.
 */
class PaymentDueTrigger implements NotificationTrigger
{
    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'payment_due';
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
        $interval = max(1, (int) config('notifications.timing.payment_due_interval_days', 2));

        $enrollments = Enrollment::query()
            ->where('status', 'enrolled')
            ->whereNotNull('payment_due_date')
            ->whereDate('payment_due_date', '<=', $asOf->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($enrollments as $enrollment) {
            $daysSince = (int) $enrollment->payment_due_date->startOfDay()->diffInDays($asOf->startOfDay());

            // On the date, then every second day: 0, 2, 4...
            if ($daysSince % $interval !== 0) {
                continue;
            }

            $contact = $this->recipients->primaryContactFor((int) $enrollment->customer_id);

            if ($contact === null) {
                continue;
            }

            yield new NotificationCandidate(
                triggerKey: $this->key(),
                recipient: $contact,
                customerId: (int) $enrollment->customer_id,
                enrollmentId: (int) $enrollment->getKey(),
                subject: $enrollment,
                scheduledFor: $asOf,
                period: $asOf->toDateString(),
            );
        }
    }
}
