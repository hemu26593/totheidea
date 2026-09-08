<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Models\FormSubmission;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 2 - Intake forms not filled.
 *
 * Recipient: participant. Timing: daily until submitted.
 * Source: form_submissions.status.
 *
 * "Not filled" is read exactly as the architecture states it: a submission
 * that exists and is still a draft. Widening it to "a form the participant has
 * never opened" would require deciding which forms each enrolment is expected
 * to have started and by when - a rule the SOW does not give, and one that
 * would generate reminders for work nobody has been asked for yet.
 *
 * Daily cadence comes from the period being the date: one reminder per draft
 * per day, however many times the scheduler runs.
 */
class IntakeIncompleteTrigger implements NotificationTrigger
{
    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'intake_incomplete';
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
        $drafts = FormSubmission::query()
            ->where('status', 'draft')
            ->orderBy('id')
            ->get();

        foreach ($drafts as $draft) {
            $contact = $this->recipients->primaryContactFor((int) $draft->customer_id);

            if ($contact === null) {
                continue;
            }

            yield new NotificationCandidate(
                triggerKey: $this->key(),
                recipient: $contact,
                customerId: (int) $draft->customer_id,
                enrollmentId: (int) $draft->enrollment_id,
                subject: $draft,
                scheduledFor: $asOf,
                period: $asOf->toDateString(),
            );
        }
    }
}
