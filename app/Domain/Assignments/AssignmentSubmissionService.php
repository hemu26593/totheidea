<?php

declare(strict_types=1);

namespace App\Domain\Assignments;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The submission side of an assignment: start, submit, resubmit.
 *
 * INVARIANT I4 - THE ASSIGNMENT INSTANCE AND THE ENROLMENT MUST RESOLVE TO
 * THE SAME BATCH. It is a multi-hop rule:
 *
 *     assignment_instance -> session_instance -> batch
 *     enrollment                              -> batch
 *
 * No foreign key spans it, so it is asserted here and tested explicitly.
 * Without it a participant can file work against another cohort's released
 * assignment, and the mistake looks entirely normal in every listing.
 *
 * INVARIANT I15 - the redundant customer_id is copied from the enrolment.
 *
 * RESUBMISSION ADVANCES attempt_number ON THE SAME ROW. A second row would
 * violate UNIQUE (assignment_instance_id, enrollment_id) and, more to the
 * point, would make "how many participants have submitted?" answer wrongly.
 */
class AssignmentSubmissionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Open a submission record. Idempotent: the unique key means there is
     * exactly one per participant per released assignment.
     */
    public function start(
        AssignmentInstance $instance,
        Enrollment $enrollment,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): AssignmentSubmission {
        $this->assertSameBatch($instance, $enrollment);
        $this->assertIsOpen($instance);
        $this->assertGrantMatches($grant, $enrollment);

        $existing = $this->find($instance, $enrollment);

        if ($existing !== null) {
            return $existing;
        }

        $source = $this->resolveSource($actor, $grant);

        return DB::transaction(function () use ($instance, $enrollment, $actor, $grant, $source): AssignmentSubmission {
            $submission = new AssignmentSubmission;
            $submission->forceFill([
                'assignment_instance_id' => $instance->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'customer_id' => $enrollment->customer_id,
                'status' => AssignmentSubmission::STATUS_IN_PROGRESS,
                'attempt_number' => 1,
                'source' => $source,
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            return $submission->fresh();
        });
    }

    /**
     * Hand the work in.
     *
     * A returned submission submitted again is a RESUBMISSION: attempt_number
     * advances, so the next review is a decision on a new attempt rather than
     * a second decision on the old one.
     */
    public function submit(
        AssignmentInstance $instance,
        Enrollment $enrollment,
        ?string $body = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): AssignmentSubmission {
        $submission = $this->start($instance, $enrollment, $actor, $grant);

        if ($submission->status === AssignmentSubmission::STATUS_ACCEPTED) {
            throw new RuntimeException(sprintf(
                'Submission %d has been accepted; there is nothing left to submit.',
                $submission->getKey(),
            ));
        }

        $isResubmission = $submission->status === AssignmentSubmission::STATUS_RETURNED;

        return DB::transaction(function () use ($submission, $body, $actor, $grant, $isResubmission): AssignmentSubmission {
            $before = ['status' => $submission->status, 'attempt_number' => $submission->attempt_number];

            $submission->forceFill([
                'status' => AssignmentSubmission::STATUS_SUBMITTED,
                'body' => $body ?? $submission->body,
                // Advance ONLY on a resubmission: a first submission is
                // attempt 1, which the row already carries.
                'attempt_number' => $isResubmission
                    ? (int) $submission->attempt_number + 1
                    : (int) $submission->attempt_number,
                'submitted_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::AssignmentSubmitted,
                $submission,
                $before,
                [
                    'status' => AssignmentSubmission::STATUS_SUBMITTED,
                    'attempt_number' => $submission->fresh()->attempt_number,
                    'resubmission' => $isResubmission,
                ],
                $actor,
                $grant !== null ? ActorSource::ExternalGrant : null,
                $grant,
            );

            return $submission->fresh();
        });
    }

    public function find(AssignmentInstance $instance, Enrollment $enrollment): ?AssignmentSubmission
    {
        return AssignmentSubmission::query()
            ->where('assignment_instance_id', $instance->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->first();
    }

    /**
     * INVARIANT I4, across three joins.
     */
    public function assertSameBatch(AssignmentInstance $instance, Enrollment $enrollment): void
    {
        $sessionInstance = $instance->sessionInstance()->first();

        if ($sessionInstance === null) {
            // Fail closed: an assignment whose batch cannot be established is
            // refused rather than assumed compatible.
            throw CustomerIsolationException::unverifiableSubject($instance->getMorphClass());
        }

        if ((int) $sessionInstance->batch_id !== (int) $enrollment->batch_id) {
            throw CustomerIsolationException::mismatch(
                'assignment instance '.$instance->getKey(),
                'batch '.$enrollment->batch_id,
                'batch '.$sessionInstance->batch_id,
            );
        }
    }

    private function assertIsOpen(AssignmentInstance $instance): void
    {
        if (! $instance->isReleased()) {
            throw new RuntimeException(sprintf(
                'Assignment instance %d is [%s]; work can only be filed against a released '
                .'assignment.',
                $instance->getKey(),
                $instance->status,
            ));
        }
    }

    private function assertGrantMatches(?AccessGrant $grant, Enrollment $enrollment): void
    {
        if ($grant !== null && (int) $grant->enrollment_id !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'grant '.$grant->getKey(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$grant->enrollment_id,
            );
        }
    }

    private function resolveSource(?User $actor, ?AccessGrant $grant): ActorSource
    {
        return match (true) {
            $grant !== null => ActorSource::ExternalGrant,
            $actor !== null => ActorSource::InternalUser,
            default => ActorSource::System,
        };
    }
}
