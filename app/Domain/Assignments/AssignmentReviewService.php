<?php

declare(strict_types=1);

namespace App\Domain\Assignments;

use App\Enums\AuditAction;
use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accepting or returning submitted work.
 *
 * REVIEWS ARE HISTORICAL RECORDS. Every decision is appended with the attempt
 * it judged, so "returned, resubmitted, returned again, accepted" survives in
 * full, with each remark attached to the attempt it was about. Editing the
 * submission's status alone would collapse that to "accepted" and lose every
 * remark.
 *
 * A review is ALWAYS an internal act: reviewed_by is NOT NULL and there is no
 * actor triple, so an external grant cannot reach this table at all.
 *
 * UNIQUE (assignment_submission_id, attempt_number) enforces one decision per
 * attempt. Two decisions on one attempt would make the current status
 * ambiguous, and the constraint is in the database rather than only here.
 */
class AssignmentReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function accept(AssignmentSubmission $submission, User $reviewer, ?string $remark = null): AssignmentReview
    {
        return $this->decide($submission, $reviewer, AssignmentReview::DECISION_ACCEPTED, $remark);
    }

    public function returnForRework(
        AssignmentSubmission $submission,
        User $reviewer,
        ?string $remark = null,
    ): AssignmentReview {
        return $this->decide($submission, $reviewer, AssignmentReview::DECISION_RETURNED, $remark);
    }

    /**
     * The decision history, oldest attempt first.
     *
     * @return Collection<int, AssignmentReview>
     */
    public function history(AssignmentSubmission $submission)
    {
        return AssignmentReview::query()
            ->where('assignment_submission_id', $submission->getKey())
            ->orderBy('attempt_number')
            ->get();
    }

    private function decide(
        AssignmentSubmission $submission,
        User $reviewer,
        string $decision,
        ?string $remark,
    ): AssignmentReview {
        $this->assertIsReviewable($submission);
        $this->assertAttemptNotAlreadyDecided($submission);

        return DB::transaction(function () use ($submission, $reviewer, $decision, $remark): AssignmentReview {
            $review = new AssignmentReview;
            $review->forceFill([
                'assignment_submission_id' => $submission->getKey(),
                'decision' => $decision,
                'remark' => $remark,
                // The attempt this decision judged, captured at review time.
                'attempt_number' => (int) $submission->attempt_number,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $before = ['status' => $submission->status];

            $submission->forceFill([
                'status' => $decision === AssignmentReview::DECISION_ACCEPTED
                    ? AssignmentSubmission::STATUS_ACCEPTED
                    : AssignmentSubmission::STATUS_RETURNED,
            ])->save();

            $this->audit->log(
                $decision === AssignmentReview::DECISION_ACCEPTED
                    ? AuditAction::AssignmentAccepted
                    : AuditAction::AssignmentReturned,
                $submission,
                $before,
                [
                    'status' => $submission->fresh()->status,
                    'attempt_number' => $review->attempt_number,
                    'review_id' => $review->getKey(),
                ],
                $reviewer,
            );

            return $review->fresh();
        });
    }

    private function assertIsReviewable(AssignmentSubmission $submission): void
    {
        if ($submission->status !== AssignmentSubmission::STATUS_SUBMITTED) {
            throw new RuntimeException(sprintf(
                'Submission %d is [%s]; only submitted work can be reviewed.',
                $submission->getKey(),
                $submission->status,
            ));
        }
    }

    /**
     * The service-level form of UNIQUE (submission, attempt_number), so the
     * caller gets an intelligible message rather than a constraint violation.
     */
    private function assertAttemptNotAlreadyDecided(AssignmentSubmission $submission): void
    {
        $exists = AssignmentReview::query()
            ->where('assignment_submission_id', $submission->getKey())
            ->where('attempt_number', $submission->attempt_number)
            ->exists();

        if ($exists) {
            throw new RuntimeException(sprintf(
                'Attempt %d of submission %d has already been decided. A changed mind is a new '
                .'review on a new attempt, never an edit.',
                $submission->attempt_number,
                $submission->getKey(),
            ));
        }
    }
}
