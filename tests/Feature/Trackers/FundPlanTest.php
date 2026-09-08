<?php

declare(strict_types=1);

namespace Tests\Feature\Trackers;

use App\Domain\Trackers\FundPlanService;
use App\Enums\AuditAction;
use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\PlanningType;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\FundPlan;
use App\Models\FundPlanLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The monthly fund plan.
 *
 * Planning, not accounting. The vocabulary - BFP, FBP, EBP, SM, CD, LD, LLD,
 * BIP - is the client's and is carried exactly as supplied.
 */
class FundPlanTest extends TestCase
{
    use RefreshDatabase;

    private FundPlanService $fundPlans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->fundPlans = app(FundPlanService::class);
    }

    // --- Vocabulary ----------------------------------------------------------

    #[Test]
    public function the_planning_types_are_the_confirmed_four(): void
    {
        $this->assertSame(['BFP', 'FBP', 'EBP', 'SM'], PlanningType::values());

        // The four codes are what the schema stores and what the client uses.
        // The human-readable labels come from the Phase 0 enum and are pinned
        // here so an expansion can never drift into a guess.
        //
        // NOTE: the Phase 0 label for FBP reads "For-cast Base Planning";
        // the Phase 6 brief writes it "For-cast base planning". A casing
        // difference in a display label, flagged rather than silently
        // changed - Phase 0 is frozen, and this is the client's word to
        // settle.
        $this->assertSame('Basic Finance Planning', PlanningType::BasicFinancePlanning->label());
        $this->assertSame('For-cast Base Planning', PlanningType::ForcastBasePlanning->label());
        $this->assertSame('External Base Planning', PlanningType::ExternalBasePlanning->label());
        $this->assertSame('Strategic Management', PlanningType::StrategicManagement->label());
    }

    #[Test]
    public function the_due_classifications_are_exactly_three(): void
    {
        $this->assertSame(['CD', 'LD', 'LLD'], DueClassification::values());
        $this->assertSame('Current Due', DueClassification::CurrentDue->label());
        $this->assertSame('Long Due', DueClassification::LongDue->label());
        $this->assertSame('Long Long Due', DueClassification::LongLongDue->label());
    }

    #[Test]
    public function the_sections_are_the_confirmed_five(): void
    {
        $this->assertSame([
            'fund_in', 'fund_out', 'marketing_budget', 'sales_closing', 'production',
        ], FundPlanSection::values());
    }

    // --- The month header ------------------------------------------------------

    #[Test]
    public function a_month_is_created_with_its_budget(): void
    {
        $plan = $this->fundPlans->createMonth(
            Enrollment::factory()->create(), 2026, 10, 250000, $this->admin(),
        );

        $this->assertSame(2026, $plan->year);
        $this->assertSame(10, $plan->month);
        $this->assertSame('250000.00', $plan->budget_total);
        $this->assertTrue($plan->isDraft());
    }

    #[Test]
    public function a_month_cannot_be_planned_twice(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->fundPlans->createMonth($enrollment, 2026, 10);

        // This is what preserves history: each month is its own row set.
        $this->expectException(QueryException::class);
        $this->fundPlans->createMonth($enrollment, 2026, 10);
    }

    #[Test]
    public function an_impossible_month_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 13);
    }

    #[Test]
    public function historical_months_survive_later_ones(): void
    {
        $enrollment = Enrollment::factory()->create();
        $march = $this->fundPlans->createMonth($enrollment, 2026, 3, 100000);
        $this->fundPlans->approve($march, $this->admin());

        $this->fundPlans->createMonth($enrollment, 2026, 4, 120000);

        $this->assertTrue($march->fresh()->isApproved());
        $this->assertSame('100000.00', $march->fresh()->budget_total);
        $this->assertSame(2, FundPlan::query()->where('enrollment_id', $enrollment->getKey())->count());
    }

    // --- Lines: the three dimensions --------------------------------------------

    #[Test]
    public function a_line_carries_where_it_sits_how_it_was_planned_and_how_aged_it_is(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);

        $line = $this->fundPlans->addLine(
            $plan,
            FundPlanSection::FundIn,
            PlanningType::ForcastBasePlanning,
            DueClassification::LongDue,
            'Retainer arrears',
            plannedAmount: 40000,
        );

        $this->assertSame(FundPlanSection::FundIn, $line->section);
        $this->assertSame(PlanningType::ForcastBasePlanning, $line->planning_type);
        $this->assertSame(DueClassification::LongDue, $line->due_classification);
    }

    #[Test]
    public function bip_is_a_line_label_under_sales_closing_not_a_column(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);

        $line = $this->fundPlans->addLine(
            $plan, FundPlanSection::SalesClosing, label: 'BIP', plannedAmount: 75000,
        );

        $this->assertSame('BIP', $line->label);
        $this->assertFalse(Schema::hasColumn('fund_plan_lines', 'bip'));
        $this->assertFalse(Schema::hasColumn('fund_plan_lines', 'business_in_pipeline'));
    }

    #[Test]
    public function weekly_money_in_and_out_needs_no_third_table(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);

        foreach ([1, 2, 3, 4, 5] as $week) {
            $line = $this->fundPlans->addLine(
                $plan, FundPlanSection::FundOut, weekNumber: $week, plannedAmount: 1000 * $week,
            );

            $this->assertTrue($line->isWeekly());
            $this->assertSame($week, $line->week_number);
        }

        // NULL is a month-level line.
        $monthly = $this->fundPlans->addLine($plan, FundPlanSection::FundOut, plannedAmount: 9000);
        $this->assertFalse($monthly->isWeekly());

        $this->assertFalse(Schema::hasTable('fund_plan_weeks'));
    }

    #[Test]
    public function a_week_number_outside_one_to_five_is_refused(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);

        $this->expectException(InvalidArgumentException::class);
        $this->fundPlans->addLine($plan, FundPlanSection::FundOut, weekNumber: 6);
    }

    #[Test]
    public function a_section_may_hold_several_lines_of_the_same_shape(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);

        $this->fundPlans->addLine($plan, FundPlanSection::MarketingBudget, label: 'Print', plannedAmount: 5000);
        $this->fundPlans->addLine($plan, FundPlanSection::MarketingBudget, label: 'Digital', plannedAmount: 8000);

        // Deliberately no unique key on lines.
        $this->assertCount(2, $this->fundPlans->bySection($plan->fresh())['marketing_budget']);
    }

    // --- Invariant I6 -------------------------------------------------------------

    #[Test]
    public function an_aging_classification_is_only_meaningful_on_a_receivable(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('meaningful only on a fund_in line');

        $this->fundPlans->addLine(
            $plan, FundPlanSection::Production, dueClassification: DueClassification::LongDue,
        );
    }

    #[Test]
    public function every_non_receivable_section_refuses_a_classification(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $refused = 0;

        foreach (FundPlanSection::cases() as $section) {
            if ($section === FundPlanSection::FundIn) {
                continue;
            }

            try {
                $this->fundPlans->addLine($plan, $section, dueClassification: DueClassification::CurrentDue);
                $this->fail("[{$section->value}] should refuse a due classification.");
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }

        $this->assertSame(4, $refused, 'Four of the five sections carry no aging dimension.');
        $this->assertTrue(FundPlanSection::FundIn->acceptsDueClassification());
    }

    #[Test]
    public function updating_a_line_cannot_smuggle_a_classification_onto_the_wrong_section(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $line = $this->fundPlans->addLine($plan, FundPlanSection::FundOut, plannedAmount: 100);

        $this->expectException(InvalidArgumentException::class);
        $this->fundPlans->updateLine($line, ['due_classification' => DueClassification::LongLongDue]);
    }

    #[Test]
    public function all_long_due_across_months_is_one_query(): void
    {
        // The query the classification dimension exists to make possible.
        $enrollment = Enrollment::factory()->create();

        foreach ([3, 4, 5] as $month) {
            $plan = $this->fundPlans->createMonth($enrollment, 2026, $month);
            $this->fundPlans->addLine($plan, FundPlanSection::FundIn, dueClassification: DueClassification::LongDue, plannedAmount: 1000);
            $this->fundPlans->addLine($plan, FundPlanSection::FundIn, dueClassification: DueClassification::CurrentDue, plannedAmount: 500);
        }

        $longDue = $this->fundPlans->linesByDueClassification($enrollment, DueClassification::LongDue);

        $this->assertCount(3, $longDue);
    }

    // --- Approval -------------------------------------------------------------------

    #[Test]
    public function admin_can_approve_a_month(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $admin = $this->admin();

        $approved = $this->fundPlans->approve($plan, $admin);

        $this->assertTrue($approved->isApproved());
        $this->assertSame($admin->getKey(), $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $log = AuditLog::query()->where('action', AuditAction::FundPlanApproved)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->getKey(), $log->actor_id);
    }

    #[Test]
    public function staff_cannot_approve_a_month(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $staff = $this->staff();

        // Staff holds fund_plans.manage but not fund_plans.approve. That
        // separation is what makes approval mean anything.
        $this->assertTrue($staff->can('fund_plans.manage'));
        $this->assertFalse($staff->can('fund_plans.approve'));
        $this->assertFalse($staff->can('approve', $plan));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fund_plans.approve is required');

        $this->fundPlans->approve($plan, $staff);
    }

    #[Test]
    public function the_service_refuses_even_if_a_caller_forgets_to_authorize(): void
    {
        // The refusal is in the service as well as the policy, so no job,
        // command or future controller can approve by omission.
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $staff = $this->staff();

        try {
            $this->fundPlans->approve($plan, $staff);
            $this->fail('Expected the approval to be refused.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue($plan->fresh()->isDraft());
        $this->assertNull($plan->fresh()->approved_by);
    }

    #[Test]
    public function an_approved_month_is_not_approved_again(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $this->fundPlans->approve($plan, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->fundPlans->approve($plan->fresh(), $this->admin());
    }

    #[Test]
    public function an_approved_months_lines_do_not_change(): void
    {
        $plan = $this->fundPlans->createMonth(Enrollment::factory()->create(), 2026, 10);
        $this->fundPlans->approve($plan, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('record of what was approved');

        $this->fundPlans->addLine($plan->fresh(), FundPlanSection::FundOut, plannedAmount: 100);
    }

    // --- Not accounting ---------------------------------------------------------------

    #[Test]
    public function this_is_planning_not_accounting(): void
    {
        foreach ([
            'invoices', 'invoice_lines', 'payments', 'ledger_entries',
            'journal_entries', 'accounts', 'transactions', 'bank_accounts',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "[{$table}] is accounting, not planning.");
        }

        foreach (['invoice_number', 'payment_reference', 'gateway_id', 'tax_amount'] as $column) {
            $this->assertFalse(Schema::hasColumn('fund_plan_lines', $column));
        }
    }

    #[Test]
    public function a_fund_plan_is_never_removed(): void
    {
        $plan = FundPlan::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never removed');

        $plan->delete();
    }

    #[Test]
    public function lines_belong_to_their_plan_and_go_with_it(): void
    {
        $plan = FundPlan::factory()->create();
        FundPlanLine::factory()->count(3)->create(['fund_plan_id' => $plan->getKey()]);

        $this->assertCount(3, $plan->fresh()->lines);

        $fk = collect(Schema::getForeignKeys('fund_plan_lines'))
            ->first(fn (array $f): bool => $f['columns'] === ['fund_plan_id']);

        $this->assertSame('cascade', strtolower((string) $fk['on_delete']));
    }

    #[Test]
    public function a_plan_belongs_to_one_participant_only(): void
    {
        $mine = Enrollment::factory()->create();
        $theirs = Enrollment::factory()->create();

        $this->fundPlans->createMonth($mine, 2026, 10);
        $this->fundPlans->createMonth($theirs, 2026, 10);

        $this->assertSame(1, FundPlan::query()->where('enrollment_id', $mine->getKey())->count());
    }
}
