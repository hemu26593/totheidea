<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Models\AssignmentInstance;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 3 - Assignment due.
 *
 * Recipient: participant. Timing: 2 days before, and on the day.
 * Source: assignment_instances.due_at.
 *
 * Only a RELEASED assignment is due. A draft has not been given to anyone, and
 * a closed one is no longer accepting work.
 */
class AssignmentDueTrigger implements NotificationTrigger
{
    use ConcernsReleasedAssignments;

    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'assignment_due';
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
        $leadDays = (array) config('notifications.timing.assignment_due_lead_days', [2, 0]);

        foreach ($leadDays as $lead) {
            $target = $asOf->addDays((int) $lead)->toDateString();

            $instances = AssignmentInstance::query()
                ->where('status', AssignmentInstance::STATUS_RELEASED)
                ->whereDate('due_at', $target)
                ->orderBy('id')
                ->get();

            foreach ($instances as $instance) {
                foreach ($this->enrollmentsAwaiting($instance) as $enrollment) {
                    $contact = $this->recipients->primaryContactFor((int) $enrollment->customer_id);

                    if ($contact === null) {
                        continue;
                    }

                    yield new NotificationCandidate(
                        triggerKey: $this->key(),
                        recipient: $contact,
                        customerId: (int) $enrollment->customer_id,
                        enrollmentId: (int) $enrollment->getKey(),
                        subject: $instance,
                        scheduledFor: $asOf,
                        period: $target.'+lead'.(int) $lead,
                    );
                }
            }
        }
    }
}
