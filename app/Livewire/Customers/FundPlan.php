<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Trackers\FundPlanService;
use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\PlanningType;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FundPlan as FundPlanModel;
use App\Models\FundPlanLine;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE MONTHLY FUND PLAN. Money in, money out, marketing budget, sales closing
 * and production, for one month.
 *
 * APPROVAL IS ADMIN-ONLY AND IS NOT A TOGGLE. Staff hold fund_plans.manage but
 * not fund_plans.approve, so the approve control simply does not exist for
 * them - and FundPlanPolicy refuses it independently of what this screen
 * renders. Once approved, the plan is closed: FundPlanService refuses further
 * lines, which is why the add controls disappear rather than erroring.
 *
 * DUE CLASSIFICATION (CD / LD / LLD) IS VALID ONLY ON FUND-IN LINES. That is
 * invariant I6, enforced in the service; this screen offers the field only for
 * that section so the rule is visible rather than a surprise.
 *
 * The BFP / FBP / EBP / SM planning vocabulary is carried exactly as the
 * client defined it.
 */
class FundPlan extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    #[Url]
    public int $year = 0;

    #[Url]
    public int $month = 0;

    #[Url]
    public ?int $enrollmentId = null;

    public bool $addingLine = false;

    public string $section = 'fund_in';

    public ?string $planningType = null;

    public ?string $dueClassification = null;

    public string $label = '';

    public ?int $weekNumber = null;

    public ?float $plannedAmount = null;

    public ?float $actualAmount = null;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);

        $this->year = $this->year ?: (int) now()->year;
        $this->month = $this->month ?: (int) now()->month;
        $this->enrollmentId ??= $customer->enrollments()->where('status', 'enrolled')->value('id')
            ?? $customer->enrollments()->value('id');
    }

    public function createMonth(FundPlanService $plans): void
    {
        $this->authorize('create', FundPlanModel::class);

        $enrollment = $this->enrollment();

        $this->runGuarded(
            fn () => $plans->createMonth($enrollment, $this->year, $this->month, null, auth()->user()),
            'Month created.',
        );
    }

    public function startLine(string $section): void
    {
        $this->authorize('create', FundPlanLine::class);

        $this->reset(['planningType', 'dueClassification', 'label', 'weekNumber', 'plannedAmount', 'actualAmount']);
        $this->section = $section;
        $this->addingLine = true;
    }

    public function addLine(FundPlanService $plans): void
    {
        $this->authorize('create', FundPlanLine::class);

        $this->validate([
            'section' => ['required', 'string', 'in:'.implode(',', array_column(FundPlanSection::cases(), 'value'))],
            'planningType' => ['nullable', 'string', 'in:'.implode(',', array_column(PlanningType::cases(), 'value'))],
            'dueClassification' => ['nullable', 'string', 'in:'.implode(',', array_column(DueClassification::cases(), 'value'))],
            'label' => ['nullable', 'string', 'max:200'],
            'weekNumber' => ['nullable', 'integer', 'between:1,5'],
            'plannedAmount' => ['nullable', 'numeric', 'min:0'],
            'actualAmount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $plan = $this->plan();

        if ($plan === null) {
            $this->addError('domain', 'Create the month before adding lines to it.');

            return;
        }

        $saved = $this->runGuarded(function () use ($plans, $plan): void {
            $plans->addLine(
                plan: $plan,
                section: FundPlanSection::from($this->section),
                planningType: $this->planningType ? PlanningType::from($this->planningType) : null,
                dueClassification: $this->dueClassification ? DueClassification::from($this->dueClassification) : null,
                label: $this->label ?: null,
                weekNumber: $this->weekNumber,
                plannedAmount: $this->plannedAmount,
                actualAmount: $this->actualAmount,
            );
        }, 'Line added.');

        if ($saved) {
            $this->addingLine = false;
        }
    }

    public function approve(FundPlanService $plans): void
    {
        $plan = $this->plan();

        if ($plan === null) {
            abort(404);
        }

        // Guarded by the policy, which asks for fund_plans.approve. Staff do
        // not hold it and never see the control either.
        $this->authorize('approve', $plan);

        $this->runGuarded(
            fn () => $plans->approve($plan, auth()->user()),
            'Fund plan approved. It is now closed to further lines.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();
        $plan = $this->plan();

        return view('livewire.customers.fund-plan', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            'plan' => $plan,
            'bySection' => $plan === null ? [] : app(FundPlanService::class)->bySection($plan),
            'sections' => FundPlanSection::cases(),
            'planningTypes' => PlanningType::cases(),
            'dueClassifications' => DueClassification::cases(),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Fund Plan']);
    }

    private function plan(): ?FundPlanModel
    {
        if ($this->enrollmentId === null) {
            return null;
        }

        return FundPlanModel::query()
            ->where('enrollment_id', $this->enrollment()->getKey())
            ->where('year', $this->year)
            ->where('month', $this->month)
            ->first();
    }

    private function enrollment(): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($this->enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }
}
