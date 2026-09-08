<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Assignments\AssignmentReviewService;
use App\Domain\Assignments\AssignmentSubmissionService;
use App\Domain\Sessions\CurriculumService;
use App\Enums\AuditAction;
use App\Models\AssignmentInstance;
use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentTemplate;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SessionInstance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Release -> submit -> return -> resubmit -> accept, and the history that
 * survives it.
 */
class AssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private AssignmentReleaseService $release;

    private AssignmentSubmissionService $submissions;

    private AssignmentReviewService $reviews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->release = app(AssignmentReleaseService::class);
        $this->submissions = app(AssignmentSubmissionService::class);
        $this->reviews = app(AssignmentReviewService::class);
    }

    /**
     * @return array{0: AssignmentInstance, 1: Enrollment}
     */
    private function releasedAssignment(): array
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        return [$instance, $enrollment];
    }

    // --- The wording snapshot ---------------------------------------------

    #[Test]
    public function release_snapshots_the_wording_rather_than_pointing_at_the_template(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $template = AssignmentTemplate::factory()->create([
            'session_template_id' => $session->session_template_id,
            'title' => 'Draft your Q1 time grid',
            'instructions' => 'Fill every quadrant.',
        ]);

        $instance = $this->release->stageFromTemplate($session, $template, now()->addWeek());
        $this->release->release($instance, $this->admin());

        // Now the curriculum is edited, as it legitimately may be.
        app(CurriculumService::class);
        $template->forceFill([
            'title' => 'Draft your Q1 AND Q2 time grid',
            'instructions' => 'Completely different instructions.',
        ])->save();

        $instance->refresh();

        // The running batch keeps what it was actually given.
        $this->assertSame('Draft your Q1 time grid', $instance->title);
        $this->assertSame('Fill every quadrant.', $instance->instructions);
    }

    #[Test]
    public function an_assignment_may_be_released_without_a_template(): void
    {
        $session = SessionInstance::factory()->held()->create();

        $instance = $this->release->stageAdHoc($session, 'One-off exercise', now()->addWeek());

        $this->assertNull($instance->assignment_template_id);
        $this->assertSame('One-off exercise', $instance->title);
    }

    #[Test]
    public function a_template_from_another_session_cannot_be_released_here(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $foreign = AssignmentTemplate::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->release->stageFromTemplate($session, $foreign, now()->addWeek());
    }

    #[Test]
    public function releasing_and_closing_are_audited(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->create(['session_instance_id' => $session->getKey()]);
        $actor = $this->admin();

        $this->release->release($instance, $actor);
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AssignmentReleased)->first());

        $this->release->close($instance->fresh(), $actor, 'Session over');
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AssignmentClosed)->first());
    }

    #[Test]
    public function a_released_assignment_is_closed_never_deleted(): void
    {
        $instance = AssignmentInstance::factory()->released()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('closed, never deleted');

        $instance->delete();
    }

    // --- Submission --------------------------------------------------------

    #[Test]
    public function work_cannot_be_filed_against_an_unreleased_assignment(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $draft = AssignmentInstance::factory()->create(['session_instance_id' => $session->getKey()]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only be filed against a released assignment');

        $this->submissions->submit($draft, $enrollment, 'Here it is.');
    }

    #[Test]
    public function there_is_one_submission_row_per_participant_per_assignment(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();

        $this->submissions->submit($instance, $enrollment, 'First');
        $this->submissions->start($instance, $enrollment);

        $this->assertSame(1, AssignmentSubmission::query()
            ->where('assignment_instance_id', $instance->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->count());
    }

    #[Test]
    public function a_second_submission_row_is_blocked_by_the_database(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();
        $this->submissions->submit($instance, $enrollment, 'First');

        $this->expectException(QueryException::class);

        AssignmentSubmission::factory()->create([
            'assignment_instance_id' => $instance->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $enrollment->customer_id,
        ]);
    }

    // --- Resubmission ------------------------------------------------------

    #[Test]
    public function resubmission_advances_the_attempt_number_on_the_same_row(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();
        $reviewer = $this->admin();

        $first = $this->submissions->submit($instance, $enrollment, 'First attempt');
        $this->assertSame(1, $first->attempt_number);

        $this->reviews->returnForRework($first, $reviewer, 'Needs the numbers.');

        $second = $this->submissions->submit($instance, $enrollment, 'Second attempt');

        $this->assertSame(2, $second->attempt_number);
        $this->assertSame($first->getKey(), $second->getKey(), 'Resubmission must not create a second row.');
    }

    #[Test]
    public function accepted_work_cannot_be_submitted_again(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();
        $submission = $this->submissions->submit($instance, $enrollment, 'Done');
        $this->reviews->accept($submission, $this->admin(), 'Good.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing left to submit');

        $this->submissions->submit($instance, $enrollment, 'Again');
    }

    // --- Review history ----------------------------------------------------

    #[Test]
    public function the_full_return_resubmit_accept_history_survives(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();
        $reviewer = $this->admin();

        $submission = $this->submissions->submit($instance, $enrollment, 'Attempt one');
        $this->reviews->returnForRework($submission, $reviewer, 'Missing the fund plan.');

        $submission = $this->submissions->submit($instance, $enrollment, 'Attempt two');
        $this->reviews->returnForRework($submission, $reviewer, 'Fund plan is there, dates are not.');

        $submission = $this->submissions->submit($instance, $enrollment, 'Attempt three');
        $this->reviews->accept($submission, $reviewer, 'Accepted.');

        $history = $this->reviews->history($submission->fresh());

        // Without the reviews table this whole sequence would read "accepted"
        // and every remark would be gone.
        $this->assertCount(3, $history);
        $this->assertSame([1, 2, 3], $history->pluck('attempt_number')->all());
        $this->assertSame(
            ['returned', 'returned', 'accepted'],
            $history->pluck('decision')->all(),
        );
        $this->assertSame('Missing the fund plan.', $history[0]->remark);
        $this->assertSame('Fund plan is there, dates are not.', $history[1]->remark);
        $this->assertSame(AssignmentSubmission::STATUS_ACCEPTED, $submission->fresh()->status);
    }

    #[Test]
    public function one_attempt_gets_exactly_one_decision(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();
        $submission = $this->submissions->submit($instance, $enrollment, 'Attempt one');
        $reviewer = $this->admin();

        $this->reviews->returnForRework($submission, $reviewer, 'No.');

        // The submission is now 'returned', so a second decision on attempt 1
        // is refused before the unique key is even reached.
        $this->expectException(RuntimeException::class);

        $this->reviews->accept($submission->fresh(), $reviewer, 'Actually yes.');
    }

    #[Test]
    public function two_decisions_on_one_attempt_are_blocked_by_the_database(): void
    {
        $submission = AssignmentSubmission::factory()->submitted()->create();
        AssignmentReview::factory()->create([
            'assignment_submission_id' => $submission->getKey(),
            'attempt_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        AssignmentReview::factory()->create([
            'assignment_submission_id' => $submission->getKey(),
            'attempt_number' => 1,
        ]);
    }

    #[Test]
    public function only_submitted_work_can_be_reviewed(): void
    {
        [$instance, $enrollment] = $this->releasedAssignment();
        $submission = $this->submissions->start($instance, $enrollment, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only submitted work can be reviewed');

        $this->reviews->accept($submission, $this->admin());
    }

    // --- Reviews are immutable --------------------------------------------

    #[Test]
    public function a_review_cannot_be_edited(): void
    {
        $review = AssignmentReview::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $review->forceFill(['remark' => 'Rewritten history.'])->save();
    }

    #[Test]
    public function a_review_cannot_be_deleted(): void
    {
        $review = AssignmentReview::factory()->create();

        $this->expectException(RuntimeException::class);
        $review->delete();
    }

    #[Test]
    public function not_even_a_super_admin_can_rewrite_a_review(): void
    {
        // 'update' is not a guarded ability, so Gate::before would grant it.
        // The guarantee is on the model for exactly that reason.
        $this->actingAs($this->superAdmin());
        $review = AssignmentReview::factory()->create();

        $this->expectException(RuntimeException::class);

        $review->forceFill(['decision' => AssignmentReview::DECISION_ACCEPTED])->save();
    }

    #[Test]
    public function a_review_is_always_an_internal_act(): void
    {
        // No actor triple on this table at all: an external grant cannot reach
        // it, by construction rather than by a check.
        foreach (['source', 'created_by', 'access_grant_id'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('assignment_reviews', $column),
                "assignment_reviews must not carry [{$column}] - review is internal-only."
            );
        }

        $this->assertTrue(
            Schema::hasColumn('assignment_reviews', 'reviewed_by')
        );
    }
}
