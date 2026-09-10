<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\RecipientResolver;
use App\Domain\Sessions\AttendanceCalculator;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;

/**
 * TRIGGER 8 - Attendance below 90%.
 *
 * Recipient: participant AND the internal user running the batch.
 * Timing: as soon as it happens.
 * Source: session_attendances.
 *
 * [CLIENT DECISION - ATTENDANCE WEIGHTING]
 *
 * THIS IS THE INTEGRATION CONTRACT, NOT A CALCULATION. Everything about the
 * notification is built here - who is asked about, who hears, what the subject
 * is, how it deduplicates. What is NOT here, and must not be, is any opinion
 * about how `late` and `excused` weigh in the 90% figure.
 *
 * The eligibility question is delegated in full to AttendanceCalculator, which
 * takes the unbound AttendanceWeighting contract. So:
 *
 *   - while no weighting is bound, unresolvedDependency() blocks the trigger
 *     and the scheduler reports it as deferred;
 *   - the day the client answers and a weighting is bound, this trigger starts
 *     working with no change to this file.
 *
 * Phase 4's five skipped weighting tests stay skipped. Nothing here decides
 * what they are waiting for.
 */
class AttendanceBelowThresholdTrigger implements NotificationTrigger
{
    use ConcernsEnrolledParticipants;

    public function __construct(private readonly RecipientResolver $recipients) {}

    public function key(): string
    {
        return 'attendance_below_threshold';
    }

    public function unresolvedDependency(): ?string
    {
        if (app()->bound(AttendanceWeighting::class)) {
            return null;
        }

        return '[CLIENT DECISION - ATTENDANCE WEIGHTING] no AttendanceWeighting implementation is '
            .'bound. How `late` and `excused` affect the numerator and the denominator of the 90% '
            .'rule is unanswered, and the figure gates a warning sent to the participant and the '
            .'consultant.';
    }

    /**
     * @return iterable<int, NotificationCandidate>
     */
    public function candidates(CarbonImmutable $asOf): iterable
    {
        // Resolved here rather than injected, so that constructing this
        // trigger never requires the client's answer - the registry builds all
        // nine at boot.
        $calculator = app(AttendanceCalculator::class);

        foreach (Batch::query()->whereNull('archived_at')->orderBy('id')->get() as $batch) {
            $totalSessions = SessionInstance::query()
                ->where('batch_id', $batch->getKey())
                ->where('status', '!=', SessionInstance::STATUS_CANCELLED)
                ->count();

            if ($totalSessions === 0) {
                continue;
            }

            foreach ($this->activeEnrollmentsInBatch((int) $batch->getKey()) as $enrollment) {
                // "Can no longer reach it" - the warning fires when the BEST
                // POSSIBLE final figure drops below the threshold, which is
                // strictly earlier than the current percentage doing so. Both
                // the numerator and the denominator behind this call are the
                // client's to define.
                if ($calculator->isStillReachable($enrollment, $totalSessions)) {
                    continue;
                }

                yield from $this->recipientsFor($enrollment, $batch, $asOf);
            }
        }
    }

    /**
     * @return iterable<int, NotificationCandidate>
     */
    private function recipientsFor(Enrollment $enrollment, Batch $batch, CarbonImmutable $asOf): iterable
    {
        $contact = $this->recipients->primaryContactFor((int) $enrollment->customer_id);

        if ($contact !== null) {
            yield new NotificationCandidate(
                triggerKey: $this->key(),
                recipient: $contact,
                customerId: (int) $enrollment->customer_id,
                enrollmentId: (int) $enrollment->getKey(),
                subject: $enrollment,
                scheduledFor: $asOf,
                // Crossing the threshold is a one-way door within a batch, so
                // the enrolment alone is the period: this is said once, not
                // once a day for the rest of the programme.
                period: 'enrollment:'.$enrollment->getKey(),
            );
        }

        foreach ($this->recipients->internalUsersForBatch($batch) as $user) {
            yield new NotificationCandidate(
                triggerKey: $this->key(),
                recipient: $user,
                customerId: (int) $enrollment->customer_id,
                enrollmentId: (int) $enrollment->getKey(),
                subject: $enrollment,
                scheduledFor: $asOf,
                period: 'enrollment:'.$enrollment->getKey(),
            );
        }
    }
}
