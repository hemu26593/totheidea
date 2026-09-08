<?php

declare(strict_types=1);

namespace Tests\Feature\Trackers;

use App\Domain\Trackers\ActionItemService;
use App\Domain\Trackers\DayPlanService;
use App\Domain\Trackers\FundPlanService;
use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\MmdTargetService;
use App\Domain\Trackers\TargetVsActualCalculator;
use App\Domain\Trackers\TimeGridService;
use App\Domain\Trackers\WeeklyReviewProjector;
use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Exceptions\CustomerIsolationException;
use App\Models\ActionItem;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use App\Models\FundPlan;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\TimeGridEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer A against Customer B, across all five trackers.
 *
 * This is the file that matters most in Phase 6. Every tracker holds data a
 * business would be alarmed to see leak, and every one of them is reachable by
 * an id in a request. So the question is asked five times, deliberately
 * repetitively: can A read B, and can A write to B?
 *
 * Ownership is always resolved server-side from the enrolment or the customer
 * the caller actually passed - never from an id supplied alongside it.
 */
class TrackerIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two complete, unrelated businesses.
     *
     * @return array{0: Customer, 1: Enrollment, 2: Customer, 3: Enrollment}
     */
    private function twoBusinesses(): array
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        return [
            $a, Enrollment::factory()->create(['customer_id' => $a->getKey()]),
            $b, Enrollment::factory()->create(['customer_id' => $b->getKey()]),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    // --- Day Plan ------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_day_plan(): void
    {
        [$a, $aEnrollment, $b, $bEnrollment] = $this->twoBusinesses();
        $dayPlans = app(DayPlanService::class);

        $dayPlans->create($aEnrollment, '2026-10-01', 'A private task', $this->admin());
        $dayPlans->create($bEnrollment, '2026-10-01', 'B private task', $this->admin());

        $forA = $dayPlans->forDay($aEnrollment, '2026-10-01');

        $this->assertCount(1, $forA);
        $this->assertSame('A private task', $forA->first()->task);

        $scoped = DayPlanItem::query()->forCustomer((int) $a->getKey())->get();
        $this->assertCount(1, $scoped);
        $this->assertNotContains((int) $b->getKey(), $scoped->pluck('customer_id')->all());
    }

    #[Test]
    public function a_day_plan_row_cannot_be_reparented_to_another_business(): void
    {
        [, $aEnrollment, $b] = $this->twoBusinesses();
        $item = app(DayPlanService::class)->create($aEnrollment, '2026-10-01', 'Task', $this->admin());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $item->forceFill(['customer_id' => $b->getKey()])->save();
    }

    // --- Time Grid ------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_time_grid(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $timeGrid = app(TimeGridService::class);

        $timeGrid->record($aEnrollment, 2026, 1, 'A activity', 100.0);
        $timeGrid->record($bEnrollment, 2026, 1, 'B activity', 200.0);

        $grid = $timeGrid->gridFor($aEnrollment, 2026);

        $this->assertCount(1, $grid);
        $this->assertSame('A activity', $grid->first()->activity);
    }

    #[Test]
    public function the_same_activity_name_in_two_businesses_does_not_collide(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $timeGrid = app(TimeGridService::class);

        // The unique key is scoped by enrolment, so two businesses may plan
        // the same activity without one blocking the other.
        $timeGrid->record($aEnrollment, 2026, 1, 'Selling', 100.0);
        $timeGrid->record($bEnrollment, 2026, 1, 'Selling', 200.0);

        $this->assertSame(2, TimeGridEntry::query()->count());
    }

    // --- MMD --------------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_figures(): void
    {
        [$a, , $b] = $this->twoBusinesses();
        $entries = app(MmdEntryService::class);

        $entries->record($a, '2026-10-06', ['fund_in' => 100]);
        $entries->record($b, '2026-10-06', ['fund_in' => 999999]);

        $this->assertCount(1, $entries->entriesFor($a, '2026-10-06'));
        $this->assertSame(
            100.0,
            app(TargetVsActualCalculator::class)->actual($a, 'fund_in', '2026-10-01', '2026-10-31'),
        );
        $this->assertSame(100.0, app(WeeklyReviewProjector::class)->week($a, '2026-10-06')['T']['fund_in']);
    }

    #[Test]
    public function an_enrolment_from_another_business_cannot_be_attributed_to_these_figures(): void
    {
        [$a, , , $bEnrollment] = $this->twoBusinesses();

        $this->expectException(CustomerIsolationException::class);

        app(MmdEntryService::class)->record($a, '2026-10-01', ['fund_in' => 100], $bEnrollment);
    }

    #[Test]
    public function a_scorecard_never_mixes_two_businesses(): void
    {
        [$a, $aEnrollment, $b] = $this->twoBusinesses();
        $entries = app(MmdEntryService::class);

        $entries->record($a, '2026-10-06', ['production' => 100], $aEnrollment);
        $entries->record($b, '2026-10-06', ['production' => 999999]);

        app(MmdTargetService::class)->set(
            $aEnrollment, 'production', MmdTarget::PERIOD_MONTHLY,
            '2026-10-01', '2026-10-31', 500, $this->admin(),
        );

        $scorecard = app(TargetVsActualCalculator::class)->scorecard($aEnrollment->fresh());

        $this->assertCount(1, $scorecard);
        $this->assertSame(100.0, $scorecard[0]['actual']);
    }

    #[Test]
    public function a_target_belongs_to_one_participant(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $targets = app(MmdTargetService::class);

        $targets->set($aEnrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 100, $this->admin());
        $targets->set($bEnrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 900, $this->admin());

        // Same metric, same period, two participants - and neither overwrote
        // the other.
        $this->assertSame(2, MmdTarget::query()->count());
        $this->assertSame('100.00', $targets->find($aEnrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01')->target_value);
    }

    // --- Fund Plan ------------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_fund_plan(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $fundPlans = app(FundPlanService::class);

        $aPlan = $fundPlans->createMonth($aEnrollment, 2026, 10, 100);
        $bPlan = $fundPlans->createMonth($bEnrollment, 2026, 10, 999999);
        $fundPlans->addLine($aPlan, FundPlanSection::FundOut, plannedAmount: 50);
        $fundPlans->addLine($bPlan, FundPlanSection::FundOut, plannedAmount: 88888);

        $this->assertCount(1, $aPlan->fresh()->lines);
        $this->assertSame('50.00', $aPlan->fresh()->lines->first()->planned_amount);

        $mine = FundPlan::query()->where('enrollment_id', $aEnrollment->getKey())->get();
        $this->assertCount(1, $mine);
    }

    #[Test]
    public function the_long_due_query_never_crosses_businesses(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $fundPlans = app(FundPlanService::class);

        $aPlan = $fundPlans->createMonth($aEnrollment, 2026, 10);
        $bPlan = $fundPlans->createMonth($bEnrollment, 2026, 10);
        $fundPlans->addLine($aPlan, FundPlanSection::FundIn, dueClassification: DueClassification::LongDue, plannedAmount: 100);
        $fundPlans->addLine($bPlan, FundPlanSection::FundIn, dueClassification: DueClassification::LongDue, plannedAmount: 999999);

        $lines = $fundPlans->linesByDueClassification($aEnrollment, DueClassification::LongDue);

        $this->assertCount(1, $lines);
        $this->assertSame('100.00', $lines->first()->planned_amount);
    }

    // --- Action Plan --------------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_action_list(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $items = app(ActionItemService::class);

        $items->create($aEnrollment, 'A commitment');
        $items->create($bEnrollment, 'B commitment');

        $open = $items->openFor($aEnrollment);

        $this->assertCount(1, $open);
        $this->assertSame('A commitment', $open->first()->title);
    }

    #[Test]
    public function customer_a_cannot_mutate_customer_b_action_item(): void
    {
        [, $aEnrollment, , $bEnrollment] = $this->twoBusinesses();
        $items = app(ActionItemService::class);
        $theirs = $items->create($bEnrollment, 'B commitment');

        $this->expectException(\RuntimeException::class);

        $items->assertBelongsToEnrollment($theirs, $aEnrollment);
    }

    // --- Every tracker at once ------------------------------------------------------------

    #[Test]
    public function every_tracker_resolves_ownership_and_none_leaks(): void
    {
        [$a, $aEnrollment, $b, $bEnrollment] = $this->twoBusinesses();

        app(DayPlanService::class)->create($bEnrollment, '2026-10-01', 'B task', $this->admin());
        app(TimeGridService::class)->record($bEnrollment, 2026, 1, 'B activity', 10.0);
        app(MmdEntryService::class)->record($b, '2026-10-01', ['fund_in' => 1], $bEnrollment);
        app(MmdTargetService::class)->set($bEnrollment, 'fund_in', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 1, $this->admin());
        app(FundPlanService::class)->createMonth($bEnrollment, 2026, 10);
        app(ActionItemService::class)->create($bEnrollment, 'B commitment');

        // Nothing of B's is visible from A's context.
        $this->assertCount(0, app(DayPlanService::class)->forDay($aEnrollment, '2026-10-01'));
        $this->assertCount(0, app(TimeGridService::class)->gridFor($aEnrollment, 2026));
        $this->assertCount(0, app(MmdEntryService::class)->entriesFor($a, '2026-10-01'));
        $this->assertSame([], app(TargetVsActualCalculator::class)->scorecard($aEnrollment));
        $this->assertSame(0, FundPlan::query()->where('enrollment_id', $aEnrollment->getKey())->count());
        $this->assertCount(0, app(ActionItemService::class)->openFor($aEnrollment));

        // And B's data is all still there.
        $this->assertSame(1, DayPlanItem::query()->where('customer_id', $b->getKey())->count());
        $this->assertSame(1, MmdEntry::query()->where('customer_id', $b->getKey())->count());
        $this->assertSame(1, ActionItem::query()->where('enrollment_id', $bEnrollment->getKey())->count());
    }
}
