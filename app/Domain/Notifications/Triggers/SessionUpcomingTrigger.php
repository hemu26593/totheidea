<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 1 - Session coming up.
 *
 * Recipient: participant. Timing: 3 days before, and that morning.
 * Source: session_instances.planned_date.
 *
 * Both lead times come from the SOW section 7 table; neither is invented, and
 * they live in config so a change is a configuration change rather than a code
 * change.
 *
 * A cancelled session is not upcoming. A completed one is not either - the
 * planned date can be in the future while the session was held early.
 */
class SessionUpcomingTrigger implements NotificationTrigger
{
    use ConcernsEnrolledParticipants;

    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'session_upcoming';
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
        $leadDays = (array) config('notifications.timing.session_upcoming_lead_days', [3, 0]);

        foreach ($leadDays as $lead) {
            $target = $asOf->addDays((int) $lead)->toDateString();

            $sessions = SessionInstance::query()
                ->whereDate('planned_date', $target)
                ->whereIn('status', [SessionInstance::STATUS_SCHEDULED, SessionInstance::STATUS_IN_PROGRESS])
                ->orderBy('id')
                ->get();

            foreach ($sessions as $session) {
                foreach ($this->activeEnrollmentsInBatch((int) $session->batch_id) as $enrollment) {
                    $contact = $this->recipients->primaryContactFor((int) $enrollment->customer_id);

                    if ($contact === null) {
                        continue;
                    }

                    yield new NotificationCandidate(
                        triggerKey: $this->key(),
                        recipient: $contact,
                        customerId: (int) $enrollment->customer_id,
                        enrollmentId: (int) $enrollment->getKey(),
                        subject: $session,
                        scheduledFor: $asOf,
                        // The two reminders for one session are different
                        // notifications, so the lead time is part of the
                        // period. Without it the second would look like a
                        // duplicate of the first and never go out.
                        period: $target.'+lead'.(int) $lead,
                    );
                }
            }
        }
    }
}
