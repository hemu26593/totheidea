<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Sessions\AttendanceCalculator;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Domain\Sessions\CurriculumService;
use App\Domain\Sessions\SessionSchedulingService;
use App\Livewire\Admin\Curriculum;
use App\Livewire\Assignments\Show as AssignmentScreen;
use App\Livewire\Customers\Assignments as CustomerAssignments;
use App\Livewire\Customers\Attendance as CustomerAttendance;
use App\Livewire\Sessions\Index;
use App\Livewire\Sessions\Show as SessionScreen;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use App\Models\User;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT: running Session 1, setting an assignment, reviewing it, and marking
 * attendance - the week-to-week work of the programme.
 */
class DeliveryWorkflowUatTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private Batch $batch;

    /** @var array<int, SessionTemplate> */
    private array $curriculum = [];

    /** @var array<int, Enrollment> */
    private array $enrolments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->buildProgramme();
    }

    #[Test]
    public function the_curriculum_is_sessions_one_to_six_and_no_more(): void
    {
        $this->assertCount(6, $this->curriculum);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            $this->program->sessionTemplates()->pluck('sequence')->map(fn ($s): int => (int) $s)->all(),
        );

        // The screen offers exactly those and does not invent 7-18.
        $this->assertSame([1, 2, 3, 4, 5, 6], Index::SESSION_SEQUENCES);
        $this->assertSame(6, Curriculum::MAX_SEQUENCE);
    }

    #[Test]
    public function session_one_runs_from_scheduled_to_completed_with_attendance_and_an_assignment(): void
    {
        $admin = $this->admin();
        $session = $this->scheduleSession(0, now()->subWeek());

        $screen = Livewire::actingAs($admin)->test(SessionScreen::class, ['session' => $session]);

        // Before the session is held, attendance cannot be marked - the domain
        // refuses a mark against a session that never happened (I16).
        $this->assertSame(SessionInstance::STATUS_SCHEDULED, $session->status);
        $screen->assertSee('Start the session first');

        $screen->call('mark', $this->enrolments[0]->getKey(), SessionAttendance::STATUS_PRESENT)
            ->assertHasErrors('domain');
        $this->assertSame(0, SessionAttendance::query()->count());

        // Start it, recording the date it was actually held.
        $screen->set('actualDate', now()->subWeek()->toDateString())->call('begin')->assertHasNoErrors();
        $session->refresh();

        $this->assertSame(SessionInstance::STATUS_IN_PROGRESS, $session->status);
        $this->assertNotNull($session->actual_date);

        // Now mark the register.
        $screen = Livewire::actingAs($admin)->test(SessionScreen::class, ['session' => $session]);
        $screen->call('mark', $this->enrolments[0]->getKey(), SessionAttendance::STATUS_PRESENT);
        $screen->call('mark', $this->enrolments[1]->getKey(), SessionAttendance::STATUS_ABSENT);

        $this->assertSame(2, SessionAttendance::query()->count());

        // A correction AMENDS rather than overwrites - the change is on record.
        $screen->call('mark', $this->enrolments[1]->getKey(), SessionAttendance::STATUS_LATE);

        $amended = SessionAttendance::query()
            ->where('enrollment_id', $this->enrolments[1]->getKey())->firstOrFail();

        $this->assertSame(SessionAttendance::STATUS_LATE, $amended->status);
        $this->assertNotNull($amended->amended_at, 'A corrected mark records that it was corrected.');
        $this->assertSame(2, SessionAttendance::query()->count(), 'No duplicate mark was created.');

        // Stage and release the assignment set at this session.
        $template = $this->curriculum[0]->assignmentTemplates()->firstOrFail();

        $screen->call('startAssignmentRelease')
            ->set('assignmentTemplateId', $template->getKey())
            ->set('assignmentDueAt', now()->addWeek()->toDateString())
            ->call('stageAssignment')
            ->assertHasNoErrors();

        $instance = AssignmentInstance::query()->firstOrFail();
        $this->assertSame(AssignmentInstance::STATUS_DRAFT, $instance->status,
            'Staging produces a draft; the batch has not been given it yet.');

        // A draft is invisible to the participant's own screen.
        Livewire::actingAs($admin)
            ->test(CustomerAssignments::class, ['customer' => $this->enrolments[0]->customer])
            ->assertDontSee($instance->title);

        $screen->call('releaseAssignment', $instance->getKey())->assertHasNoErrors();
        $this->assertSame(AssignmentInstance::STATUS_RELEASED, $instance->fresh()->status);

        Livewire::actingAs($admin)
            ->test(CustomerAssignments::class, ['customer' => $this->enrolments[0]->customer])
            ->assertSee($instance->title);

        // Complete the session.
        Livewire::actingAs($admin)
            ->test(SessionScreen::class, ['session' => $session->fresh()])
            ->call('completeSession')
            ->assertHasNoErrors();

        $this->assertSame(SessionInstance::STATUS_COMPLETED, $session->fresh()->status);
    }

    #[Test]
    public function the_session_screen_states_what_was_recorded_without_a_percentage(): void
    {
        $admin = $this->admin();
        $session = $this->heldSession(0);

        Livewire::actingAs($admin)->test(SessionScreen::class, ['session' => $session])
            ->call('mark', $this->enrolments[0]->getKey(), SessionAttendance::STATUS_PRESENT)
            ->call('mark', $this->enrolments[1]->getKey(), SessionAttendance::STATUS_EXCUSED);

        $rendered = Livewire::actingAs($admin)->test(SessionScreen::class, ['session' => $session->fresh()]);

        $rendered->assertSee('Present')->assertSee('Absent')->assertSee('Late')->assertSee('Excused');
        $rendered->assertSee('No attendance percentage is shown');

        foreach (['%', 'Attendance rate', '90%'] as $forbidden) {
            $rendered->assertDontSee($forbidden);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Assignment lifecycle, including resubmission
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_assignment_goes_submitted_returned_resubmitted_accepted(): void
    {
        $admin = $this->admin();
        $reviewer = $this->admin();
        $instance = $this->releasedAssignment();
        $alpha = $this->enrolments[0];

        // Staff record the submission on the participant's behalf - the
        // participant has no account.
        Livewire::actingAs($admin)
            ->test(CustomerAssignments::class, ['customer' => $alpha->customer])
            ->call('startSubmission', $instance->getKey(), $alpha->getKey())
            ->set('body', 'First attempt: we described the process but did not measure it.')
            ->call('submit')
            ->assertHasNoErrors();

        $submission = AssignmentSubmission::query()->firstOrFail();
        $this->assertSame(AssignmentSubmission::STATUS_SUBMITTED, $submission->status);
        $this->assertSame(1, (int) $submission->attempt_number);
        $this->assertSame('internal_user', (string) $submission->source->value,
            'A staff-entered submission says so on the record.');

        // Reviewer returns it for rework, with a reason.
        Livewire::actingAs($reviewer)
            ->test(AssignmentScreen::class, ['assignment' => $instance])
            ->call('startReview', $submission->getKey())
            ->set('reviewRemark', 'Needs the dispatch numbers, not just a description.')
            ->call('returnForRework')
            ->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(AssignmentSubmission::STATUS_RETURNED, $submission->status);
        $this->assertSame(1, $submission->reviews()->count());

        // A return with no reason is refused: it is not actionable.
        Livewire::actingAs($reviewer)
            ->test(AssignmentScreen::class, ['assignment' => $instance])
            ->call('startReview', $submission->getKey())
            ->set('reviewRemark', '')
            ->call('returnForRework')
            ->assertHasErrors('reviewRemark');

        // Resubmission advances the attempt; the first attempt stays on record.
        Livewire::actingAs($admin)
            ->test(CustomerAssignments::class, ['customer' => $alpha->customer])
            ->call('startSubmission', $instance->getKey(), $alpha->getKey())
            ->set('body', 'Second attempt: 42 dispatches a week, 9 late.')
            ->call('submit')
            ->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(2, (int) $submission->attempt_number, 'Resubmission advances the attempt.');
        $this->assertSame(AssignmentSubmission::STATUS_SUBMITTED, $submission->status);
        $this->assertSame(1, AssignmentSubmission::query()->count(), 'One submission row, not two.');

        // Accept it.
        Livewire::actingAs($reviewer)
            ->test(AssignmentScreen::class, ['assignment' => $instance])
            ->call('startReview', $submission->getKey())
            ->set('reviewRemark', 'Clear now. Accepted.')
            ->call('accept')
            ->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(AssignmentSubmission::STATUS_ACCEPTED, $submission->status);

        // Both judgements survive, in order, with their authors.
        $this->assertSame(2, $submission->reviews()->count());
        $this->assertSame(
            ['returned', 'accepted'],
            $submission->reviews()->orderBy('attempt_number')->pluck('decision')->all(),
        );
    }

    #[Test]
    public function overdue_is_a_view_over_released_assignments_and_not_a_fourth_status(): void
    {
        $admin = $this->admin();
        $instance = $this->releasedAssignment(dueAt: now()->subDays(3));

        $this->assertSame(
            ['draft', 'released', 'closed'],
            AssignmentInstance::STATUSES,
            'The domain has three assignment statuses.',
        );

        $screen = Livewire::actingAs($admin)
            ->test(\App\Livewire\Assignments\Index::class)
            ->set('overdueOnly', true);

        $screen->assertSee($instance->title)->assertSee('Overdue');

        // The row is still RELEASED underneath the label.
        $this->assertSame(AssignmentInstance::STATUS_RELEASED, $instance->fresh()->status);
    }

    #[Test]
    public function a_closed_assignment_accepts_no_further_submission(): void
    {
        $admin = $this->admin();
        $instance = $this->releasedAssignment();
        $alpha = $this->enrolments[0];

        Livewire::actingAs($admin)
            ->test(AssignmentScreen::class, ['assignment' => $instance])
            ->call('close')
            ->assertHasNoErrors();

        $this->assertSame(AssignmentInstance::STATUS_CLOSED, $instance->fresh()->status);

        Livewire::actingAs($admin)
            ->test(CustomerAssignments::class, ['customer' => $alpha->customer])
            ->call('startSubmission', $instance->getKey(), $alpha->getKey())
            ->set('body', 'Late attempt.')
            ->call('submit')
            ->assertHasErrors('domain');

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance across several sessions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function attendance_history_reads_correctly_across_several_sessions(): void
    {
        $admin = $this->admin();
        $alpha = $this->enrolments[0];

        $expected = [
            SessionAttendance::STATUS_PRESENT,
            SessionAttendance::STATUS_LATE,
            SessionAttendance::STATUS_ABSENT,
            SessionAttendance::STATUS_EXCUSED,
        ];

        foreach ($expected as $index => $status) {
            $session = $this->heldSession($index);

            Livewire::actingAs($admin)
                ->test(SessionScreen::class, ['session' => $session])
                ->call('mark', $alpha->getKey(), $status);
        }

        $this->assertSame(4, SessionAttendance::query()->where('enrollment_id', $alpha->getKey())->count());

        $screen = Livewire::actingAs($admin)
            ->test(CustomerAttendance::class, ['customer' => $alpha->customer]);

        // Every status the domain has, counted - and nothing derived from them.
        $screen->assertViewHas('counts', fn ($counts) => $counts['present'] === 1
            && $counts['late'] === 1
            && $counts['absent'] === 1
            && $counts['excused'] === 1);

        $screen->assertSee('Counts, not a percentage');
    }

    #[Test]
    public function the_attendance_weighting_decision_is_still_open_and_nothing_pretends_otherwise(): void
    {
        // KNOWN / CLIENT DECISION. AttendanceCalculator exists and is correct;
        // it cannot be constructed because no weighting has been agreed. This
        // records that state rather than working around it.
        $this->assertFalse(app()->bound(AttendanceWeighting::class));

        $this->expectException(BindingResolutionException::class);
        app(AttendanceCalculator::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    private function buildProgramme(): void
    {
        $author = User::factory()->create();
        $author->assignRole('super-admin');

        $this->program = Program::factory()->create([
            'name' => 'Business Mastery Programme',
            'session_count' => 6,
        ]);

        $curriculum = app(CurriculumService::class);
        $titles = ['Foundation', 'Marketing systems', 'Sales and closing',
            'People and structure', 'Delivery and feedback', 'Finance and growth'];

        foreach ($titles as $index => $title) {
            $template = $curriculum->defineSession($this->program, $index + 1, $title, 'Theme', 'Objectives.', $author);
            $curriculum->defineAssignment($template, 'Worksheet for '.$title, 'Complete and bring it back.', 7, false, 0, $author);
            $this->curriculum[] = $template;
        }

        $this->batch = Batch::factory()->create([
            'program_id' => $this->program->getKey(),
            'name' => 'Vadodara Batch A',
            'code' => 'B-2026-A',
        ]);

        foreach ([['Alpha Metalworks', 'C-ALPHA'], ['Beta Textiles', 'C-BETA']] as [$name, $code]) {
            $customer = Customer::factory()->create(['name' => $name, 'code' => $code]);

            $this->enrolments[] = Enrollment::factory()->create([
                'customer_id' => $customer->getKey(),
                'batch_id' => $this->batch->getKey(),
            ]);
        }
    }

    private function scheduleSession(int $index, ?\DateTimeInterface $plannedDate = null): SessionInstance
    {
        return app(SessionSchedulingService::class)->schedule(
            $this->batch,
            $this->curriculum[$index],
            ($plannedDate ?? now())->format('Y-m-d'),
            $this->admin(),
            'Intan Networks, Vadodara',
        );
    }

    private function heldSession(int $index): SessionInstance
    {
        $session = $this->scheduleSession($index, now()->subWeeks(6 - $index));

        return app(SessionSchedulingService::class)
            ->begin($session, now()->subWeeks(6 - $index)->toDateString(), $this->admin());
    }

    private function releasedAssignment(?\DateTimeInterface $dueAt = null): AssignmentInstance
    {
        $session = $this->heldSession(0);
        $template = $this->curriculum[0]->assignmentTemplates()->firstOrFail();

        $releases = app(AssignmentReleaseService::class);
        $instance = $releases->stageFromTemplate(
            $session,
            $template,
            ($dueAt ?? now()->addWeek())->format('Y-m-d'),
            $this->admin(),
        );

        return $releases->release($instance, $this->admin());
    }
}
