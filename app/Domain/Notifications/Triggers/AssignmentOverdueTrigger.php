<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Models\AssignmentInstance;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 4 - Assignment overdue.
 *
 * Recipient: participant AND the internal user running the session.
 * Timing: the next day, then every 3.
 * Source: assignment_instances.due_at against submissions.
 *
 * "Every 3" is a cadence, not a daily send: day 1, day 4, day 7. Being overdue
 * stays true indefinitely, which is precisely why the dedupe key carries the
 * date - without it this trigger alone would send every single morning until
 * the work arrived.
 */
class AssignmentOverdueTrigger implements NotificationTrigger
{
    use ConcernsReleasedAssignments;

    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'assignment_overdue';
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
        $firstDay = (int) config('notifications.timing.assignment_overdue_first_day', 1);
        $interval = max(1, (int) config('notifications.timing.assignment_overdue_interval_days', 3));

        $instances = AssignmentInstance::query()
            ->where('status', AssignmentInstance::STATUS_RELEASED)
            ->whereDate('due_at', '<', $asOf->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($instances as $instance) {
            $daysOverdue = (int) $instance->due_at->startOfDay()->diffInDays($asOf->startOfDay());

            if ($daysOverdue < $firstDay || ($daysOverdue - $firstDay) % $interval !== 0) {
                continue;
            }

            $sessionInstance = $instance->sessionInstance()->first();

            foreach ($this->enrollmentsAwaiting($instance) as $enrollment) {
                $contact = $this->recipients->primaryContactFor((int) $enrollment->customer_id);

                if ($contact !== null) {
                    yield new NotificationCandidate(
                        triggerKey: $this->key(),
                        recipient: $contact,
                        customerId: (int) $enrollment->customer_id,
                        enrollmentId: (int) $enrollment->getKey(),
                        subject: $instance,
                        scheduledFor: $asOf,
                        period: $asOf->toDateString(),
                    );
                }

                if ($sessionInstance === null) {
                    continue;
                }

                // The consultant hears about the same enrolment, so the
                // enrolment stays on the candidate: "who is this about" is not
                // the same question as "who is this to".
                foreach ($this->recipients->internalUsersForSession($sessionInstance) as $user) {
                    yield new NotificationCandidate(
                        triggerKey: $this->key(),
                        recipient: $user,
                        customerId: (int) $enrollment->customer_id,
                        enrollmentId: (int) $enrollment->getKey(),
                        subject: $instance,
                        scheduledFor: $asOf,
                        period: $asOf->toDateString(),
                    );
                }
            }
        }
    }
}
