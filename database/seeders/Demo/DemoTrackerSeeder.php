<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Trackers\ActionItemService;
use App\Domain\Trackers\DayPlanService;
use App\Domain\Trackers\FundPlanService;
use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\MmdTargetService;
use App\Domain\Trackers\TimeGridService;
use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\PlanningType;
use App\Models\ActionItem;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The day-to-day trackers: day plan, time grid, MMD, fund plan, action plan.
 *
 * A note on MMD, because the name invites assumptions. In this application it
 * is not a free-form KPI table: MmdEntry::METRICS fixes it at eight daily
 * figures - money in and out, enquiries by source, orders closed and their
 * value, and production. Seeding invented metrics such as "on-time delivery"
 * would have meant adding columns, so the demo uses the eight that exist and
 * the targets are set against those same eight.
 *
 * The time grid is likewise quarterly planned-against-actual hours per
 * activity, not a diary of clock times. Clock times belong to the day plan,
 * which has planned_start and planned_end, and that is where they are.
 *
 * Figures are derived from each business's size rather than drawn at random,
 * so a demonstration can point at a number and have it make sense next to the
 * turnover on that customer's assessment.
 */
class DemoTrackerSeeder extends Seeder
{
    /** How many recent weekdays of MMD each active business gets. */
    private const MMD_DAYS = 24;

    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();

        $enrollments = Enrollment::query()
            ->where('status', 'enrolled')
            ->with('customer')
            ->orderBy('id')
            ->get();

        foreach ($enrollments as $index => $enrollment) {
            $customer = $enrollment->customer;

            if ($customer === null) {
                continue;
            }

            $business = collect(DemoDataset::businesses())->firstWhere('code', $customer->code);

            if ($business === null) {
                continue;
            }

            $this->mmd($enrollment, $customer, $business, $actor);
            $this->targets($enrollment, $business, $actor);
            $this->dayPlan($enrollment, $actor, $index);
            $this->timeGrid($enrollment, $actor);
            $this->fundPlan($enrollment, $business, $actor);
            $this->actionPlan($enrollment, $business, $actor);
        }
    }

    /**
     * Daily operating figures for the last few weeks of working days.
     *
     * @param  array<string, mixed>  $business
     */
    private function mmd(Enrollment $enrollment, Customer $customer, array $business, User $actor): void
    {
        if (MmdEntry::query()->where('customer_id', $customer->getKey())->exists()) {
            return;
        }

        $service = app(MmdEntryService::class);

        // Daily turnover, in rupees, from the annual figure in crores.
        $dailyTurnover = ((float) str_replace(' crore', '', (string) $business['turnover']) * 10_000_000) / 300;
        // Most businesses have filed today's figures; three deliberately have
        // not, so "dashboards missing today" is a real handful rather than
        // everybody or nobody.
        $filesToday = ! in_array($customer->code, ['BMP-WESTERN', 'BMP-GIRIRAJ', 'BMP-SAMARTH'], true);

        $day = CarbonImmutable::today()->addDay();
        $recorded = 0;

        while ($recorded < self::MMD_DAYS) {
            $day = $day->subDay();

            if ($day->isToday() && ! $filesToday) {
                continue;
            }

            if ($day->isSunday()) {
                continue;
            }

            $recorded++;

            // A repeatable wobble: the same business always has the same day.
            $swing = 0.7 + ((($day->dayOfYear + strlen((string) $customer->code)) % 7) / 10);
            $closed = (int) max(1, round(($business['headcount'] / 18) * $swing));

            $service->record(
                $customer,
                $day->toDateString(),
                [
                    'fund_in' => round($dailyTurnover * $swing, 2),
                    'fund_out' => round($dailyTurnover * $swing * 0.78, 2),
                    'enquiries_new' => (int) max(1, round(4 * $swing)),
                    'enquiries_repeat' => (int) max(1, round(6 * $swing)),
                    'enquiries_referral' => (int) round(2 * $swing),
                    'sales_closed_count' => $closed,
                    'sales_closed_value' => round($dailyTurnover * $swing * 1.1, 2),
                    'production' => round($dailyTurnover * $swing * 0.95, 2),
                ],
                $enrollment,
                $actor,
            );
        }
    }

    /**
     * A monthly target for each metric, so the target-versus-actual screens
     * have both halves.
     *
     * @param  array<string, mixed>  $business
     */
    private function targets(Enrollment $enrollment, array $business, User $actor): void
    {
        if (MmdTarget::query()->where('enrollment_id', $enrollment->getKey())->exists()) {
            return;
        }

        $service = app(MmdTargetService::class);
        $month = CarbonImmutable::today()->startOfMonth();
        $monthlyTurnover = ((float) str_replace(' crore', '', (string) $business['turnover']) * 10_000_000) / 12;

        $targets = [
            'fund_in' => round($monthlyTurnover, 2),
            'fund_out' => round($monthlyTurnover * 0.75, 2),
            'enquiries_new' => 90.0,
            'enquiries_repeat' => 130.0,
            'enquiries_referral' => 45.0,
            'sales_closed_count' => round($business['headcount'] / 1.5),
            'sales_closed_value' => round($monthlyTurnover * 1.1, 2),
            'production' => round($monthlyTurnover * 0.95, 2),
        ];

        foreach ($targets as $metric => $value) {
            $service->set(
                $enrollment,
                $metric,
                MmdTarget::PERIOD_MONTHLY,
                $month->toDateString(),
                $month->endOfMonth()->toDateString(),
                (float) $value,
                $actor,
            );
        }
    }

    /**
     * Today's plan and the last few days of it, with the earlier days worked
     * through so the screen shows completed work rather than a blank list.
     */
    private function dayPlan(Enrollment $enrollment, User $actor, int $index): void
    {
        if (DayPlanItem::query()->where('enrollment_id', $enrollment->getKey())->exists()) {
            return;
        }

        $service = app(DayPlanService::class);

        $routine = [
            ['task' => 'Daily KPI review with the management team', 'start' => '09:00', 'end' => '09:30', 'g' => 'Review', 'c' => 'Management', 'm' => 'Daily'],
            ['task' => 'Sales follow-up on open quotations', 'start' => '09:30', 'end' => '10:30', 'g' => 'Sales', 'c' => 'Customer', 'm' => 'Daily'],
            ['task' => 'Production review against the day plan', 'start' => '11:00', 'end' => '12:00', 'g' => 'Operations', 'c' => 'Plant', 'm' => 'Daily'],
            ['task' => 'Customer follow-up on despatched orders', 'start' => '14:00', 'end' => '15:00', 'g' => 'Sales', 'c' => 'Customer', 'm' => 'Daily'],
            ['task' => 'Delegation review with the second line', 'start' => '16:00', 'end' => '16:30', 'g' => 'People', 'c' => 'Team', 'm' => 'Weekly'],
            ['task' => 'Cash-flow and receivables review', 'start' => '16:30', 'end' => '17:00', 'g' => 'Finance', 'c' => 'Accounts', 'm' => 'Weekly'],
        ];

        $today = CarbonImmutable::today();

        // Three working days: two behind, and today.
        for ($back = 2; $back >= 0; $back--) {
            $date = $today->subDays($back);

            if ($date->isSunday()) {
                continue;
            }

            // A different slice each day, so no two days look identical.
            $tasks = array_slice($routine, ($index + $back) % 2, 4);

            foreach ($tasks as $position => $task) {
                $item = $service->create(
                    $enrollment,
                    $date->toDateString(),
                    $task['task'],
                    $actor,
                    null,
                    $task['start'],
                    $task['end'],
                    $task['g'],
                    $task['c'],
                    $task['m'],
                    $position,
                );

                // Yesterday and before is done work, except one slip that the
                // carry-forward screen can show.
                if ($back > 0 && ! ($back === 1 && $position === 3)) {
                    $service->complete($item, 45, $actor);
                }
            }
        }
    }

    private function timeGrid(Enrollment $enrollment, User $actor): void
    {
        $service = app(TimeGridService::class);
        $now = CarbonImmutable::today();
        $quarter = (int) ceil($now->month / 3);

        $allocations = [
            ['activity' => 'Strategic planning and review', 'planned' => 24.0, 'actual' => 16.0],
            ['activity' => 'Sales and customer development', 'planned' => 60.0, 'actual' => 68.0],
            ['activity' => 'Operations and production review', 'planned' => 90.0, 'actual' => 104.0],
            ['activity' => 'People, delegation and capability', 'planned' => 36.0, 'actual' => 21.0],
            ['activity' => 'Finance, cash and receivables', 'planned' => 30.0, 'actual' => 27.0],
        ];

        foreach ($allocations as $allocation) {
            $service->record(
                $enrollment,
                $now->year,
                $quarter,
                $allocation['activity'],
                $allocation['planned'],
                $allocation['actual'],
                $actor,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $business
     */
    private function fundPlan(Enrollment $enrollment, array $business, User $actor): void
    {
        $service = app(FundPlanService::class);
        $month = CarbonImmutable::today();
        $monthly = ((float) str_replace(' crore', '', (string) $business['turnover']) * 10_000_000) / 12;

        $plan = $service->createMonth(
            $enrollment,
            $month->year,
            $month->month,
            round($monthly * 0.8, 2),
            $actor,
        );

        if ($plan->lines()->exists()) {
            return;
        }

        // Ageing sits on the fund-in side only: a due classification describes a
        // receivable, and FundPlanService refuses it anywhere else.
        $lines = [
            [FundPlanSection::FundIn, PlanningType::BasicFinancePlanning, DueClassification::CurrentDue, 'Collections due within 30 days', 1, $monthly * 0.55, $monthly * 0.48],
            [FundPlanSection::FundIn, PlanningType::BasicFinancePlanning, DueClassification::LongDue, 'Receivables beyond 60 days', 2, $monthly * 0.22, $monthly * 0.17],
            [FundPlanSection::FundIn, PlanningType::BasicFinancePlanning, DueClassification::LongLongDue, 'Receivables beyond 90 days', 3, $monthly * 0.08, $monthly * 0.11],
            [FundPlanSection::FundIn, PlanningType::ForcastBasePlanning, null, 'New orders expected to invoice', 4, $monthly * 0.35, $monthly * 0.31],
            [FundPlanSection::FundOut, PlanningType::BasicFinancePlanning, null, 'Raw material and bought-out parts', 1, $monthly * 0.38, $monthly * 0.41],
            [FundPlanSection::FundOut, PlanningType::BasicFinancePlanning, null, 'Salaries and wages', 2, $monthly * 0.18, $monthly * 0.18],
            [FundPlanSection::FundOut, PlanningType::StrategicManagement, null, 'Equipment upgrade — instalment', 3, $monthly * 0.06, $monthly * 0.06],
            [FundPlanSection::MarketingBudget, PlanningType::StrategicManagement, null, 'Sales and marketing — exhibitions and collateral', 1, $monthly * 0.03, $monthly * 0.02],
            [FundPlanSection::MarketingBudget, PlanningType::StrategicManagement, null, 'Technology and automation', 2, $monthly * 0.04, $monthly * 0.01],
            [FundPlanSection::SalesClosing, PlanningType::ForcastBasePlanning, null, 'Orders expected to close this month', 1, $monthly * 1.1, $monthly * 0.94],
            [FundPlanSection::Production, PlanningType::BasicFinancePlanning, null, 'Planned production value', 1, $monthly * 0.95, $monthly * 0.9],
            [FundPlanSection::Production, PlanningType::StrategicManagement, null, 'Hiring and training', 2, $monthly * 0.02, $monthly * 0.01],
        ];

        foreach ($lines as $position => [$section, $planning, $due, $label, $week, $planned, $actual]) {
            $service->addLine(
                $plan,
                $section,
                $planning,
                $due,
                $label,
                $week,
                round((float) $planned, 2),
                round((float) $actual, 2),
                $position,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $business
     */
    private function actionPlan(Enrollment $enrollment, array $business, User $actor): void
    {
        if (ActionItem::query()->where('enrollment_id', $enrollment->getKey())->exists()) {
            return;
        }

        $service = app(ActionItemService::class);
        $today = CarbonImmutable::today();

        $items = [
            ['Document the enquiry-to-quotation process', 'Write it as it runs today, then mark every step with no named owner.', -6, ActionItem::PRIORITY_HIGH, ActionItem::STATUS_DONE],
            ['Implement a weekly sales review', 'Monday, 09:30, fixed agenda: open quotations, lost orders, this week\'s follow-ups.', -2, ActionItem::PRIORITY_HIGH, ActionItem::STATUS_DONE],
            ['Reduce quotation turnaround to 2 days', $business['constraint'], 21, ActionItem::PRIORITY_HIGH, ActionItem::STATUS_IN_PROGRESS],
            ['Create the delegation matrix', 'List the decisions the owner makes weekly and name who could take each one.', 14, ActionItem::PRIORITY_HIGH, ActionItem::STATUS_IN_PROGRESS],
            ['Define production KPIs and publish them daily', 'Output, rejections and on-time despatch, on the board by 09:00.', 28, ActionItem::PRIORITY_NORMAL, ActionItem::STATUS_OPEN],
            ['Establish a fortnightly receivables review', 'Accounts to circulate the ageing before each review.', 10, ActionItem::PRIORITY_NORMAL, ActionItem::STATUS_OPEN],
            ['Standardise customer follow-up after despatch', 'One call within 48 hours of despatch, recorded in the register.', 35, ActionItem::PRIORITY_NORMAL, ActionItem::STATUS_OPEN],
            ['Build the weekly management dashboard', 'Eight numbers, one page, published every Monday.', 42, ActionItem::PRIORITY_LOW, ActionItem::STATUS_OPEN],
        ];

        foreach ($items as $position => [$title, $description, $dueOffset, $priority, $status]) {
            $item = $service->create(
                $enrollment,
                $title,
                $description,
                $today->addDays($dueOffset)->toDateString(),
                $priority,
                $actor,
                null,
                null,
                $position,
            );

            if ($status === ActionItem::STATUS_DONE) {
                $service->complete($item, $actor);

                continue;
            }

            if ($status === ActionItem::STATUS_IN_PROGRESS) {
                $service->transitionTo($item, ActionItem::STATUS_IN_PROGRESS, $actor);
            }
        }
    }
}
