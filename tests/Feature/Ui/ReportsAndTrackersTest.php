<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Domain\Reporting\ReportRegistry;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Domain\Trackers\MmdEntryService;
use App\Livewire\Customers\ActionPlan;
use App\Livewire\Customers\DayPlan;
use App\Livewire\Customers\Mmd;
use App\Livewire\Customers\Reports as CustomerReports;
use App\Livewire\Customers\TimeGrid;
use App\Livewire\Reports\Index as ReportsScreen;
use App\Models\ActionItem;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\Program;
use App\Models\ReportArtifact;
use App\Models\TimeGridEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reports and the five trackers, through the UI.
 *
 * Several of these assert an ABSENCE - no attendance percentage, no MMD grain
 * assumption, no diagnostic report. Those are the rules most easily lost in a
 * UI layer, because a screen is exactly where somebody would be tempted to
 * "just calculate it".
 */
class ReportsAndTrackersTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        $this->customer = Customer::factory()->create();
        $program = Program::factory()->create();
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $this->enrollment = Enrollment::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);
    }

    #[Test]
    public function only_implemented_report_builders_are_offered(): void
    {
        // `diagnostic` is part of the report_artifacts vocabulary but has no
        // builder. Offering it would be offering a button that fails.
        $offered = app(ReportRegistry::class)->keys();

        $this->assertNotContains('diagnostic', $offered);
        $this->assertSame(
            ['participant_progress', 'batch_summary', 'attendance_register', 'assignment_status'],
            $offered,
        );

        Livewire::actingAs($this->admin())
            ->test(ReportsScreen::class)
            ->assertViewHas('reportKeys', $offered)
            ->assertDontSee('Diagnostic');
    }

    #[Test]
    public function a_report_is_generated_against_the_chosen_subject(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ReportsScreen::class)
            ->set('reportKey', 'participant_progress')
            ->set('subjectId', $this->enrollment->getKey())
            ->call('export', 'pdf');

        $artifact = ReportArtifact::query()->firstOrFail();

        $this->assertSame('participant_progress', $artifact->report_key);
        $this->assertSame((int) $this->enrollment->getKey(), (int) $artifact->subject_id);
        $this->assertSame(64, strlen($artifact->checksum_sha256));
    }

    #[Test]
    public function both_formats_are_produced_from_the_same_payload(): void
    {
        $screen = Livewire::actingAs($this->admin())
            ->test(ReportsScreen::class)
            ->set('reportKey', 'participant_progress')
            ->set('subjectId', $this->enrollment->getKey());

        $screen->call('export', 'pdf');
        $screen->call('export', 'csv');

        $formats = ReportArtifact::query()->pluck('format')->sort()->values()->all();

        $this->assertSame(['csv', 'pdf'], $formats);
    }

    #[Test]
    public function no_attendance_percentage_appears_anywhere(): void
    {
        // AttendanceWeighting is unbound: late and excused carry no agreed
        // credit, so there is no authoritative figure to render.
        $this->assertFalse(
            app()->bound(AttendanceWeighting::class),
            'The weighting is still an open client decision.',
        );

        $response = $this->actingAs($this->admin())->get(route('attendance.index'));

        $response->assertOk();
        $response->assertSee('Counts below are facts', false);
        $response->assertDontSee('Attendance rate');
    }

    #[Test]
    public function the_mmd_screen_assumes_no_grain(): void
    {
        // Two rows on one day. Under the unresolved grain [B1] that is
        // legitimate, and the screen must show both rather than "the" entry.
        $entries = app(MmdEntryService::class);
        $today = now()->toDateString();

        $entries->record($this->customer, $today, ['fund_in' => 100], null, $this->admin());
        $entries->record($this->customer, $today, ['fund_in' => 250], null, $this->admin());

        $this->assertSame(2, MmdEntry::query()->count());

        Livewire::actingAs($this->admin())
            ->test(Mmd::class, ['customer' => $this->customer])
            ->set('date', $today)
            ->assertSee('2 row(s) recorded');

        // No unique constraint was assumed into existence either.
        $unique = collect(Schema::getIndexes('mmd_entries'))
            ->filter(fn (array $i): bool => $i['unique'] === true && $i['columns'] !== ['id']);

        $this->assertCount(0, $unique, 'No grain has been chosen for MMD.');
    }

    #[Test]
    public function the_mmd_screen_introduces_no_contributor_concept(): void
    {
        foreach (['contributor', 'contributor_id', 'function', 'function_id'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('mmd_entries', $column),
                "[{$column}] would be a grain decision nobody has made.",
            );
        }
    }

    #[Test]
    public function day_plan_time_grid_and_action_plan_are_three_separate_screens(): void
    {
        // Distinct routes, distinct components, distinct tables. Merging any
        // two of them would lose a distinction the client's own worksheets draw.
        $this->assertNotSame(
            route('customers.day-plan', $this->customer),
            route('customers.time-grid', $this->customer),
        );
        $this->assertNotSame(
            route('customers.day-plan', $this->customer),
            route('customers.action-plan', $this->customer),
        );

        $this->assertNotSame(DayPlanItem::class, TimeGridEntry::class);
        $this->assertNotSame(DayPlanItem::class, ActionItem::class);
    }

    #[Test]
    public function a_day_plan_task_is_created_through_the_domain_service(): void
    {
        Livewire::actingAs($this->admin())
            ->test(DayPlan::class, ['customer' => $this->customer])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('date', now()->toDateString())
            ->set('task', 'Call the top ten customers')
            ->set('g', 'G-value')
            ->call('add');

        $item = DayPlanItem::query()->firstOrFail();

        $this->assertSame('Call the top ten customers', $item->task);
        // The redundant customer_id, taken from the enrolment by the service.
        $this->assertSame((int) $this->customer->getKey(), (int) $item->customer_id);
        // G / C / M are stored verbatim, unexpanded.
        $this->assertSame('G-value', $item->g);
    }

    #[Test]
    public function the_day_plan_screen_does_not_reimplement_carry_forward(): void
    {
        // Carrying an unfinished task into the next day belongs to the domain
        // service and its scheduled job. Comments are stripped first: the
        // class docblock names carryForward() precisely to say it is NOT
        // called here, and that sentence should not fail this test.
        $code = '';

        foreach (token_get_all(file_get_contents(app_path('Livewire/Customers/DayPlan.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('carried_from_id', $code);
        $this->assertStringNotContainsString('carryForward', $code);
    }

    #[Test]
    public function the_time_grid_records_four_quarters(): void
    {
        $screen = Livewire::actingAs($this->admin())
            ->test(TimeGrid::class, ['customer' => $this->customer])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('year', 2026);

        foreach ([1, 2, 3, 4] as $quarter) {
            $screen->call('startAdding', $quarter)
                ->set('activity', 'Activity Q'.$quarter)
                ->set('plannedHours', 10)
                ->call('save');
        }

        $this->assertSame(4, TimeGridEntry::query()->count());
        $this->assertSame(
            [1, 2, 3, 4],
            TimeGridEntry::query()->orderBy('quarter')->pluck('quarter')->map(fn ($q): int => (int) $q)->all(),
        );
    }

    #[Test]
    public function an_action_item_moves_through_the_domain_statuses_only(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ActionPlan::class, ['customer' => $this->customer])
            ->set('enrollmentId', $this->enrollment->getKey())
            ->set('title', 'Fix the quote process')
            ->call('add');

        $item = ActionItem::query()->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(ActionPlan::class, ['customer' => $this->customer])
            ->call('moveTo', $item->getKey(), 'done');

        $this->assertSame(ActionItem::STATUS_DONE, $item->fresh()->status);

        // A status outside the domain's vocabulary is refused outright.
        Livewire::actingAs($this->admin())
            ->test(ActionPlan::class, ['customer' => $this->customer])
            ->call('moveTo', $item->getKey(), 'escalated')
            ->assertStatus(422);
    }

    #[Test]
    public function a_customer_report_is_scoped_to_that_customers_enrolments(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CustomerReports::class, ['customer' => $this->customer])
            ->call('export', 'pdf');

        $artifact = ReportArtifact::query()->firstOrFail();

        $this->assertSame((int) $this->enrollment->getKey(), (int) $artifact->subject_id);
        $this->assertSame((new Enrollment)->getMorphClass(), $artifact->subject_type);
    }
}
