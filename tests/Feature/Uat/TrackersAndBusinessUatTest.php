<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Domain\Attachments\DocumentService;
use App\Domain\Attachments\NoteService;
use App\Domain\Business\HrPolicyService;
use App\Domain\Trackers\ActionItemFeeder;
use App\Domain\Trackers\ActionItemService;
use App\Domain\Trackers\DayPlanService;
use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\TimeGridService;
use App\Domain\Trackers\WeeklyReviewProjector;
use App\Enums\UserRole;
use App\Livewire\Customers\ActionPlan;
use App\Livewire\Customers\BusinessSystems;
use App\Livewire\Customers\DayPlan;
use App\Livewire\Customers\Documents;
use App\Livewire\Customers\FundPlan as FundPlanScreen;
use App\Livewire\Customers\Mmd;
use App\Livewire\Customers\Notes;
use App\Livewire\Customers\TimeGrid;
use App\Models\ActionItem;
use App\Models\AssignmentInstance;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FundPlan;
use App\Models\HrPolicy;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\Note;
use App\Models\Position;
use App\Models\Program;
use App\Models\TimeGridEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT: the five trackers, the business systems, and the attachments -
 * the work a consultant does between sessions.
 */
class TrackersAndBusinessUatTest extends TestCase
{
    use RefreshDatabase;

    private Customer $alpha;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        $this->alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $program = Program::factory()->create(['session_count' => 6]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);

        $this->enrollment = Enrollment::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'batch_id' => $batch->getKey(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Day Plan
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_days_tasks_are_planned_completed_and_reopened(): void
    {
        $staff = $this->staff();
        $today = now()->toDateString();

        $screen = Livewire::actingAs($staff)->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('date', $today);

        foreach ([
            ['Review dispatch backlog', '09:00', '10:30'],
            ['Call the top ten customers', '11:00', '12:30'],
            ['Sign off month-end figures', '15:00', '16:00'],
        ] as [$task, $start, $end]) {
            $screen->call('startAdding')
                ->set('task', $task)
                ->set('plannedStart', $start)
                ->set('plannedEnd', $end)
                ->call('add')
                ->assertHasNoErrors();
        }

        $this->assertSame(3, DayPlanItem::query()->count());

        $first = DayPlanItem::query()->firstOrFail();
        $screen->call('complete', $first->getKey());
        $this->assertSame(DayPlanItem::STATUS_DONE, $first->fresh()->status);

        // A completed item is the record of what happened that day. The domain
        // offers no inverse of complete(), so the screen offers no control for
        // one - a button that reported success and changed nothing was worse
        // than its absence.
        $this->assertFalse(
            method_exists(DayPlan::class, 'reopen'),
            'The screen must not offer a transition the domain does not have.',
        );

        Livewire::actingAs($staff)->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('date', $today)
            ->assertDontSee('Reopen');

        // And the service refuses the edit outright, for every caller.
        $this->expectException(\RuntimeException::class);
        app(DayPlanService::class)->update($first->fresh(), ['task' => 'Rewritten after the fact'], $staff);
    }

    #[Test]
    public function carry_forward_copies_the_unfinished_task_and_leaves_the_original_traceable(): void
    {
        $staff = $this->staff();
        $dayPlans = app(DayPlanService::class);

        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $done = $dayPlans->create($this->enrollment, $yesterday, 'Finished yesterday', $staff);
        $dayPlans->complete($done, 60, $staff);
        $unfinished = $dayPlans->create($this->enrollment, $yesterday, 'Stock count, unfinished', $staff);

        $carried = $dayPlans->carryForward($today);

        $this->assertCount(1, $carried, 'Only the unfinished task carries.');

        $copy = $carried->first();
        $this->assertSame('Stock count, unfinished', $copy->task);
        $this->assertSame($today, $copy->plan_date->toDateString());
        $this->assertSame((int) $unfinished->getKey(), (int) $copy->carried_from_id,
            'The copy points back at where it came from.');

        // The original stays, marked as carried - the history is intact.
        $unfinished->refresh();
        $this->assertSame(DayPlanItem::STATUS_CARRIED_FORWARD, $unfinished->status);
        $this->assertSame($yesterday, $unfinished->plan_date->toDateString());
        $this->assertSame(DayPlanItem::STATUS_DONE, $done->fresh()->status);

        // IDEMPOTENT: running the sweep again produces nothing new. A second
        // run would otherwise duplicate a participant's whole day.
        $again = $dayPlans->carryForward($today);
        $this->assertCount(0, $again);
        $this->assertSame(3, DayPlanItem::query()->count());

        // The screen shows where the task came from.
        Livewire::actingAs($staff)
            ->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('date', $today)
            ->assertSee('Stock count, unfinished')
            ->assertSee('Carried forward');
    }

    #[Test]
    public function g_c_and_m_are_stored_and_shown_exactly_as_entered(): void
    {
        $staff = $this->staff();

        Livewire::actingAs($staff)->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('date', now()->toDateString())
            ->call('startAdding')
            ->set('task', 'Weekly review')
            ->set('g', 'G-value')
            ->set('c', 'C-value')
            ->set('m', 'M-value')
            ->call('add');

        $item = DayPlanItem::query()->firstOrFail();

        $this->assertSame('G-value', $item->g);
        $this->assertSame('C-value', $item->c);
        $this->assertSame('M-value', $item->m);

        // The headings are the single letters. No expansion is offered.
        $rendered = Livewire::actingAs($staff)->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey());

        foreach (['Goal', 'Growth', 'Category', 'Commitment', 'Milestone', 'Measure', 'Method'] as $invented) {
            $rendered->assertDontSee($invented);
        }
    }

    #[Test]
    public function day_plan_time_grid_and_action_plan_stay_three_separate_things(): void
    {
        $staff = $this->staff();

        // One task, one quarterly allocation, one action - three tables.
        app(DayPlanService::class)->create($this->enrollment, now()->toDateString(), 'A task', $staff);
        app(TimeGridService::class)
            ->record($this->enrollment, (int) now()->year, 1, 'Marketing', 40, null, $staff);
        app(ActionItemService::class)
            ->create($this->enrollment, 'An action', null, now()->addWeek()->toDateString(), 'normal', $staff);

        $this->assertSame(1, DayPlanItem::query()->count());
        $this->assertSame(1, TimeGridEntry::query()->count());
        $this->assertSame(1, ActionItem::query()->count());

        // Each screen shows only its own tracker.
        Livewire::actingAs($staff)->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->assertSee('A task')->assertDontSee('An action');

        Livewire::actingAs($staff)->test(ActionPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->assertSee('An action')->assertDontSee('A task');
    }

    /*
    |--------------------------------------------------------------------------
    | Time Grid
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_time_grid_holds_four_quarters_of_planned_and_actual_hours(): void
    {
        $staff = $this->staff();
        $year = (int) now()->year;

        $screen = Livewire::actingAs($staff)->test(TimeGrid::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year);

        foreach ([1, 2, 3, 4] as $quarter) {
            $screen->call('startAdding', $quarter)
                ->set('activity', 'Marketing')
                ->set('plannedHours', 40 + $quarter)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(4, TimeGridEntry::query()->count());

        // Actuals arrive after the quarter has run, as an audited amendment.
        $q1 = TimeGridEntry::query()->where('quarter', 1)->firstOrFail();

        Livewire::actingAs($staff)->test(TimeGrid::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)
            ->call('startEditing', $q1->getKey())
            ->set('actualHours', 37)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(37.0, (float) $q1->fresh()->actual_hours);
        $this->assertSame(41.0, (float) $q1->fresh()->planned_hours, 'The plan survives the actual.');

        // Planned and actual are both shown; no adherence score is computed.
        Livewire::actingAs($staff)->test(TimeGrid::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)
            ->assertSee('Planned')->assertSee('Actual')
            ->assertSee('No adherence rating or percentage is');
    }

    /*
    |--------------------------------------------------------------------------
    | MMD
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function mmd_accepts_several_rows_for_one_day_and_reads_them_all(): void
    {
        $staff = $this->staff();
        $today = now()->toDateString();

        $screen = Livewire::actingAs($staff)->test(Mmd::class, ['customer' => $this->alpha])
            ->set('date', $today);

        // Two rows for one day. Legitimate under either reading of the
        // unresolved grain, so the screen must not assume one.
        $screen->call('startRecording')
            ->set('figures.fund_in', 25000)->set('figures.fund_out', 18000)
            ->set('figures.enquiries_new', 4)->set('figures.enquiries_repeat', 2)
            ->set('figures.enquiries_referral', 1)
            ->set('figures.sales_closed_count', 3)->set('figures.sales_closed_value', 60000)
            ->set('figures.production', 120000)
            ->call('record')->assertHasNoErrors();

        $screen->call('startRecording')
            ->set('figures.fund_in', 5000)->set('figures.enquiries_new', 1)
            ->call('record')->assertHasNoErrors();

        $this->assertSame(2, MmdEntry::query()->count());

        Livewire::actingAs($staff)->test(Mmd::class, ['customer' => $this->alpha])
            ->set('date', $today)
            ->assertSee('2 row(s) recorded');

        // A blank measure stays null - blank is not zero.
        $second = MmdEntry::query()->latest('id')->firstOrFail();
        $this->assertNull($second->fund_out);
        $this->assertSame(5000.0, (float) $second->fund_in);
    }

    #[Test]
    public function the_weekly_review_sums_across_whatever_rows_exist(): void
    {
        $staff = $this->staff();
        $entries = app(MmdEntryService::class);

        // Two rows on the same Tuesday.
        $tuesday = now()->startOfWeek()->addDay()->toDateString();
        $entries->record($this->alpha, $tuesday, ['fund_in' => 10000], null, $staff);
        $entries->record($this->alpha, $tuesday, ['fund_in' => 7000], null, $staff);

        $week = app(WeeklyReviewProjector::class)->week($this->alpha, $tuesday);

        $this->assertSame(['T', 'Th', 'S'], array_keys($week), 'The three review days, in the SOW order.');
        $this->assertSame(2, $week['T']['entry_count']);
        $this->assertSame(17000.0, (float) $week['T']['fund_in'], 'Summed, not assumed to be one row.');
        $this->assertSame(0, $week['Th']['entry_count']);

        // A day with no entry is reported as absent, not as a failure.
        $recorded = app(WeeklyReviewProjector::class)->recordedOn($this->alpha, $tuesday);
        $this->assertTrue($recorded['T']);
        $this->assertFalse($recorded['Th']);
    }

    #[Test]
    public function staff_cannot_set_a_target_but_an_admin_can_and_variance_carries_no_grade(): void
    {
        $today = now()->toDateString();
        app(MmdEntryService::class)
            ->record($this->alpha, $today, ['fund_in' => 250000], null, $this->admin());

        // Staff: refused.
        Livewire::actingAs($this->staff())->test(Mmd::class, ['customer' => $this->alpha])
            ->set('targetEnrollmentId', $this->enrollment->getKey())
            ->set('targetMetric', 'fund_in')
            ->set('targetPeriodStart', now()->startOfMonth()->toDateString())
            ->set('targetPeriodEnd', now()->endOfMonth()->toDateString())
            ->set('targetValue', 400000)
            ->call('saveTarget')
            ->assertForbidden();

        // Admin: allowed.
        Livewire::actingAs($this->admin())->test(Mmd::class, ['customer' => $this->alpha])
            ->set('targetEnrollmentId', $this->enrollment->getKey())
            ->set('targetMetric', 'fund_in')
            ->set('targetPeriodStart', now()->startOfMonth()->toDateString())
            ->set('targetPeriodEnd', now()->endOfMonth()->toDateString())
            ->set('targetValue', 400000)
            ->call('saveTarget')
            ->assertHasNoErrors();

        $this->assertSame(1, MmdTarget::query()->count());

        $rendered = Livewire::actingAs($this->admin())->test(Mmd::class, ['customer' => $this->alpha])
            ->set('date', $today);

        $rendered->assertSee('Variance')
            ->assertSee('carries no grade or band');
    }

    /*
    |--------------------------------------------------------------------------
    | Fund Plan
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_monthly_fund_plan_is_built_and_only_an_admin_approves_it(): void
    {
        $staff = $this->staff();
        $year = (int) now()->year;
        $month = (int) now()->month;

        $screen = Livewire::actingAs($staff)->test(FundPlanScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)->set('month', $month);

        $screen->call('createMonth')->assertHasNoErrors();
        $plan = FundPlan::query()->firstOrFail();
        $this->assertSame(FundPlan::STATUS_DRAFT, $plan->status);

        // A line in each of the five sections.
        foreach ([
            ['fund_in', 'Retail receipts', 1, 120000],
            ['fund_out', 'Salaries', null, 210000],
            ['marketing_budget', 'Local campaign', null, 45000],
            ['sales_closing', 'Target closings', null, 40],
            ['production', 'Units planned', null, 1200],
        ] as [$section, $label, $week, $amount]) {
            $screen->call('startLine', $section)
                ->set('label', $label)
                ->set('weekNumber', $week)
                ->set('plannedAmount', $amount)
                ->call('addLine')
                ->assertHasNoErrors();
        }

        $this->assertSame(5, $plan->lines()->count());

        // Due classification is valid only on money-in (invariant I6).
        Livewire::actingAs($staff)->test(FundPlanScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)->set('month', $month)
            ->call('startLine', 'fund_out')
            ->set('label', 'Wrong place for a due classification')
            ->set('dueClassification', 'LD')
            ->call('addLine')
            ->assertHasErrors('domain');

        // Staff cannot approve. Asked of a FRESH component: a Livewire
        // instance that has thrown cannot be driven further.
        Livewire::actingAs($staff)->test(FundPlanScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)->set('month', $month)
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(FundPlan::STATUS_DRAFT, $plan->fresh()->status);

        // An Admin can, and the plan then closes to further lines.
        Livewire::actingAs($this->admin())->test(FundPlanScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)->set('month', $month)
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertSame(FundPlan::STATUS_APPROVED, $plan->fresh()->status);

        Livewire::actingAs($this->admin())->test(FundPlanScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', $year)->set('month', $month)
            ->call('startLine', 'fund_in')
            ->set('label', 'Added after approval')
            ->set('plannedAmount', 1)
            ->call('addLine')
            ->assertHasErrors('domain');

        $this->assertSame(5, $plan->fresh()->lines()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Action Plan
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function action_items_carry_a_source_and_move_only_through_domain_statuses(): void
    {
        $staff = $this->staff();

        $screen = Livewire::actingAs($staff)->test(ActionPlan::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->enrollment->getKey());

        $screen->call('startAdding')
            ->set('title', 'Document the dispatch process')
            ->set('dueDate', now()->addDays(5)->toDateString())
            ->set('priority', 'high')
            ->call('add')
            ->assertHasNoErrors();

        $item = ActionItem::query()->firstOrFail();
        $this->assertSame(ActionItem::STATUS_OPEN, $item->status);
        $this->assertSame('high', $item->priority);
        $this->assertFalse($item->wasAutoFed(), 'Added directly, not fed from a source.');

        $screen->call('moveTo', $item->getKey(), ActionItem::STATUS_IN_PROGRESS);
        $this->assertSame(ActionItem::STATUS_IN_PROGRESS, $item->fresh()->status);

        $screen->call('moveTo', $item->getKey(), ActionItem::STATUS_DONE);
        $this->assertSame(ActionItem::STATUS_DONE, $item->fresh()->status);

        $screen->call('moveTo', $item->getKey(), 'escalated')->assertStatus(422);
    }

    #[Test]
    public function an_action_item_can_be_fed_from_an_assignment_but_never_from_a_weak_skill_score(): void
    {
        $staff = $this->staff();

        $instance = AssignmentInstance::factory()->create();
        $feeder = app(ActionItemFeeder::class);

        $fed = $feeder->fromAssignment($instance, $this->enrollment);
        $this->assertTrue($fed->wasAutoFed());
        $this->assertSame($instance->getMorphClass(), $fed->source_type);

        // KNOWN / CLIENT DECISION: "weak" needs a threshold nobody has set, so
        // the feeder refuses rather than choosing one.
        $submission = FormSubmission::factory()->create();

        $this->expectException(\RuntimeException::class);
        $feeder->feedWeakSkillAreas($submission, $this->enrollment);
    }

    /*
    |--------------------------------------------------------------------------
    | HR and business systems
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_hr_policy_lifecycle_runs_draft_publish_acknowledge_supersede(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();

        // Staff build the org chart.
        $screen = Livewire::actingAs($staff)->test(BusinessSystems::class, ['customer' => $this->alpha]);

        $screen->call('startPosition')->set('positionTitle', 'Managing Director')
            ->set('holderName', 'Rakesh Patel')->call('addPosition')->assertHasNoErrors();

        $md = Position::query()->firstOrFail();

        $screen->call('startPosition')->set('positionTitle', 'Operations Manager')
            ->set('parentPositionId', $md->getKey())->set('holderName', 'Priya Shah')
            ->call('addPosition')->assertHasNoErrors();

        $this->assertSame(2, Position::query()->count());
        $ops = Position::query()->where('title', 'Operations Manager')->firstOrFail();
        $this->assertSame((int) $md->getKey(), (int) $ops->parent_position_id);

        // Staff may DRAFT a policy.
        $screen->call('startPolicy')
            ->set('policyTitle', 'Leave and attendance policy')
            ->set('policyBody', 'Leave is recorded two weeks in advance.')
            ->set('policyVersionLabel', 'v1')
            ->call('draftPolicy')
            ->assertHasNoErrors();

        $policy = HrPolicy::query()->firstOrFail();
        $this->assertSame(HrPolicy::STATUS_DRAFT, $policy->status);

        // Staff may NOT publish.
        $screen->call('publishPolicy', $policy->getKey())->assertForbidden();
        $this->assertSame(HrPolicy::STATUS_DRAFT, $policy->fresh()->status);

        // An Admin publishes, and the content becomes immutable.
        Livewire::actingAs($admin)->test(BusinessSystems::class, ['customer' => $this->alpha])
            ->call('publishPolicy', $policy->getKey())
            ->assertHasNoErrors();

        $policy->refresh();
        $this->assertSame(HrPolicy::STATUS_PUBLISHED, $policy->status);
        $this->assertNotNull($policy->published_at);

        try {
            $policy->forceFill(['body' => 'Quietly rewritten.'])->save();
            $this->fail('A published policy must be immutable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('published', strtolower($e->getMessage()));
        }

        // A refused save leaves the rejected value on the in-memory model, so
        // the next save would carry it back into the guard. Re-read first.
        $policy->refresh();
        $this->assertSame('Leave is recorded two weeks in advance.', $policy->body);

        // Acknowledgement is recorded against a POSITION, not a user account.
        Livewire::actingAs($staff)->test(BusinessSystems::class, ['customer' => $this->alpha])
            ->call('startAcknowledgement', $policy->getKey())
            ->set('acknowledgingPositionId', $ops->getKey())
            ->set('acknowledgedName', 'Priya Shah')
            ->call('recordAcknowledgement')
            ->assertHasNoErrors();

        $this->assertSame(1, $policy->acknowledgements()->count());
        $this->assertSame('Priya Shah', $policy->acknowledgements()->first()->acknowledged_name);

        // Superseding leaves the original readable and linked.
        $successor = app(HrPolicyService::class)
            ->supersede($policy, 'Leave and attendance policy', 'Revised text.', 'v2', $admin);

        $policy->refresh();
        $this->assertSame(HrPolicy::STATUS_SUPERSEDED, $policy->status);
        $this->assertSame((int) $successor->getKey(), (int) $policy->superseded_by_id);
        $this->assertSame(1, $policy->acknowledgements()->count(), 'What people signed stays on the old policy.');
    }

    /*
    |--------------------------------------------------------------------------
    | Documents and notes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function notes_are_internal_by_default_and_hidden_from_an_actor_without_the_permission(): void
    {
        $staff = $this->staff();

        Livewire::actingAs($staff)->test(Notes::class, ['customer' => $this->alpha])
            ->call('startNote')
            ->set('body', 'Owner is receptive but stretched. Keep it practical.')
            ->call('save')
            ->assertHasNoErrors();

        $note = Note::query()->firstOrFail();
        $this->assertTrue((bool) $note->is_internal, 'Internal is the default.');
        $this->assertSame((int) $staff->getKey(), (int) $note->author_id, 'An author is always a user.');

        // A user without notes.view_internal never receives it - the service
        // excludes it from the query rather than the template hiding it.
        $outsider = $this->userWithRole(UserRole::Staff);
        $outsider->revokePermissionTo('notes.view_internal');
        $outsider->roles()->detach();
        $outsider->refresh();

        $visible = app(NoteService::class)
            ->visibleToStaff($this->alpha, $this->alpha, $outsider);

        $this->assertCount(0, $visible);
    }

    #[Test]
    public function a_document_is_stored_privately_and_served_through_the_application(): void
    {
        $staff = $this->staff();

        Storage::disk('local')->put('documents/uat.txt', 'Site visit notes.');

        app(DocumentService::class)->attachByStaff(
            $this->alpha,
            [
                'disk' => 'local', 'path' => 'documents/uat.txt', 'original_name' => 'uat.txt',
                'mime_type' => 'text/plain', 'size_bytes' => 17,
            ],
            $staff,
        );

        $document = Document::query()->firstOrFail();

        $this->assertSame('local', $document->disk, 'Never the public disk.');
        $this->assertTrue((bool) $document->is_internal, 'Internal is the default.');

        Livewire::actingAs($staff)->test(Documents::class, ['customer' => $this->alpha])
            ->assertSee('uat.txt')
            ->call('download', $document->getKey());
    }
}
