<?php

declare(strict_types=1);

namespace App\Livewire\Assignments;

use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Assignments\AssignmentReviewService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One assignment: who was set it, who submitted, and the review history.
 *
 * REVIEWS ARE HISTORICAL RECORDS. Accepting or returning a submission appends
 * a review; it never edits the previous one. Returning for rework advances the
 * submission's attempt on the participant's next submit, which is the domain's
 * behaviour, not this screen's.
 *
 * Internal staff review; participants have no account. Submission on a
 * participant's behalf is a staff action recorded as such through the actor
 * triple.
 */
class Show extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public int $assignmentId;

    public ?int $reviewingSubmissionId = null;

    public string $reviewRemark = '';

    public function mount(AssignmentInstance $assignment): void
    {
        $this->authorize('view', $assignment);

        $this->assignmentId = (int) $assignment->getKey();
    }

    public function release(AssignmentReleaseService $assignments): void
    {
        $instance = $this->assignment();

        $this->authorize('release', $instance);

        $this->runGuarded(
            fn () => $assignments->release($instance, auth()->user()),
            'Assignment released.',
        );
    }

    public function close(AssignmentReleaseService $assignments): void
    {
        $instance = $this->assignment();

        $this->authorize('close', $instance);

        $this->runGuarded(
            fn () => $assignments->close($instance, auth()->user()),
            'Assignment closed.',
        );
    }

    public function startReview(int $submissionId): void
    {
        $submission = $this->submissionInAssignment($submissionId);

        $this->authorize('review', $submission);

        $this->reviewingSubmissionId = $submissionId;
        $this->reviewRemark = '';
    }

    public function accept(AssignmentReviewService $reviews): void
    {
        $submission = $this->submissionInAssignment((int) $this->reviewingSubmissionId);

        $this->authorize('review', $submission);

        $saved = $this->runGuarded(
            fn () => $reviews->accept($submission, auth()->user(), $this->reviewRemark ?: null),
            'Submission accepted.',
        );

        if ($saved) {
            $this->reviewingSubmissionId = null;
        }
    }

    public function returnForRework(AssignmentReviewService $reviews): void
    {
        $submission = $this->submissionInAssignment((int) $this->reviewingSubmissionId);

        $this->authorize('review', $submission);

        $this->validate(['reviewRemark' => ['required', 'string', 'max:2000']], [
            'reviewRemark.required' => 'Say what needs reworking — a return with no reason is not actionable.',
        ]);

        $saved = $this->runGuarded(
            fn () => $reviews->returnForRework($submission, auth()->user(), $this->reviewRemark),
            'Returned for rework.',
        );

        if ($saved) {
            $this->reviewingSubmissionId = null;
        }
    }

    public function render(): View
    {
        $assignment = $this->assignment();

        $roster = Enrollment::query()
            ->where('batch_id', $assignment->sessionInstance?->batch_id)
            ->where('status', 'enrolled')
            ->with('customer:id,name,code')
            ->get();

        $submissions = AssignmentSubmission::query()
            ->where('assignment_instance_id', $assignment->getKey())
            ->with(['enrollment.customer:id,name', 'reviews.reviewedBy'])
            ->get()
            ->keyBy('enrollment_id');

        return view('livewire.assignments.show', [
            'assignment' => $assignment,
            'roster' => $roster,
            'submissions' => $submissions,
            'reviewing' => $this->reviewingSubmissionId === null
                ? null
                : $submissions->firstWhere('id', $this->reviewingSubmissionId),
        ])->layout('components.layouts.app', ['title' => $assignment->title]);
    }

    private function assignment(): AssignmentInstance
    {
        return AssignmentInstance::query()
            ->with(['sessionInstance.batch', 'sessionInstance.sessionTemplate', 'assignmentTemplate'])
            ->findOrFail($this->assignmentId);
    }

    private function submissionInAssignment(int $submissionId): AssignmentSubmission
    {
        $submission = AssignmentSubmission::query()->findOrFail($submissionId);

        if ((int) $submission->assignment_instance_id !== $this->assignmentId) {
            abort(404);
        }

        return $submission;
    }
}
