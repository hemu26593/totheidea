<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\MmdTargetService;
use App\Domain\Trackers\TargetVsActualCalculator;
use App\Domain\Trackers\WeeklyReviewProjector;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE MMD DASHBOARD. Fund in/out, enquiries, sales and production.
 *
 * GRAIN-AGNOSTIC, BECAUSE THE GRAIN IS UNRESOLVED. Whether one MMD row means
 * one business per day or one contributor per day is an open client decision
 * [B1]. This screen therefore:
 *
 *   - never assumes a day has exactly one row;
 *   - lists every row recorded for a day, and shows the count;
 *   - reads every total through the domain's aggregating services, which are
 *     correct under either reading (one row summed is that row);
 *   - introduces no contributor or function field of any kind.
 *
 * Nothing here would need rewriting when B1 is answered - only the entry form
 * would gain a field, if the answer is contributor rows.
 *
 * TARGETS ARE ADMIN-ONLY. Setting one is an approval-shaped act; Staff hold
 * mmd.manage but not mmd.set_targets, and the control is absent for them.
 */
class Mmd extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    #[Url]
    public string $date = '';

    public bool $recording = false;

    /** @var array<string, mixed> */
    public array $figures = [];

    public bool $settingTarget = false;

    public ?int $targetEnrollmentId = null;

    public string $targetMetric = 'fund_in';

    public string $targetPeriodType = MmdTarget::PERIOD_MONTHLY;

    public string $targetPeriodStart = '';

    public string $targetPeriodEnd = '';

    public ?float $targetValue = null;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);

        $this->date = $this->date ?: now()->toDateString();
        $this->resetFigures();
    }

    public function startRecording(): void
    {
        $this->authorize('create', MmdEntry::class);

        $this->resetFigures();
        $this->recording = true;
    }

    /**
     * Record one MMD row for the selected day.
     *
     * Deliberately "record a row", not "record the day": under the unresolved
     * grain a day may legitimately carry several rows, and this screen does
     * not decide which reading is right.
     */
    public function record(MmdEntryService $entries): void
    {
        $this->authorize('create', MmdEntry::class);

        $rules = ['date' => ['required', 'date']];

        foreach (MmdEntry::METRICS as $metric) {
            $rules["figures.{$metric}"] = ['nullable', 'numeric', 'min:0'];
        }

        $this->validate($rules);

        $customer = $this->customer();

        $saved = $this->runGuarded(function () use ($entries, $customer): void {
            $entries->record(
                customer: $customer,
                entryDate: $this->date,
                figures: $this->cleanFigures(),
                enrollment: $this->attributionEnrollment($customer),
                actor: auth()->user(),
            );
        }, 'MMD row recorded.');

        if ($saved) {
            $this->recording = false;
            $this->resetFigures();
        }
    }

    public function startTarget(): void
    {
        $this->authorize('create', MmdTarget::class);

        $this->targetEnrollmentId = $this->customer()->enrollments()->where('status', 'enrolled')->value('id');
        $this->targetPeriodStart = CarbonImmutable::parse($this->date)->startOfMonth()->toDateString();
        $this->targetPeriodEnd = CarbonImmutable::parse($this->date)->endOfMonth()->toDateString();
        $this->targetValue = null;
        $this->settingTarget = true;
    }

    public function saveTarget(MmdTargetService $targets): void
    {
        $this->authorize('create', MmdTarget::class);

        $this->validate([
            'targetEnrollmentId' => ['required', 'integer'],
            'targetMetric' => ['required', 'string', 'in:'.implode(',', MmdEntry::METRICS)],
            'targetPeriodType' => ['required', 'string', 'in:'.implode(',', MmdTarget::PERIOD_TYPES)],
            'targetPeriodStart' => ['required', 'date'],
            'targetPeriodEnd' => ['required', 'date', 'after_or_equal:targetPeriodStart'],
            'targetValue' => ['required', 'numeric', 'min:0'],
        ]);

        $enrollment = $this->enrollmentInWorkspace((int) $this->targetEnrollmentId);

        $saved = $this->runGuarded(function () use ($targets, $enrollment): void {
            $targets->set(
                $enrollment,
                $this->targetMetric,
                $this->targetPeriodType,
                $this->targetPeriodStart,
                $this->targetPeriodEnd,
                (float) $this->targetValue,
                auth()->user(),
            );
        }, 'Target set.');

        if ($saved) {
            $this->settingTarget = false;
        }
    }

    public function render(): View
    {
        $customer = $this->customer();
        $day = CarbonImmutable::parse($this->date);

        return view('livewire.customers.mmd', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'metrics' => MmdEntry::METRICS,
            'moneyMetrics' => MmdEntry::MONEY_METRICS,
            // Every row for the day. Not "the row".
            'entries' => app(MmdEntryService::class)->entriesFor($customer, $this->date),
            // The T / Th / S review projection, from the domain.
            'week' => app(WeeklyReviewProjector::class)->week($customer, $this->date),
            'recordedOn' => app(WeeklyReviewProjector::class)->recordedOn($customer, $this->date),
            'reviewDays' => WeeklyReviewProjector::REVIEW_DAYS,
            'targets' => $this->targetComparisons($customer),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            'periodTypes' => MmdTarget::PERIOD_TYPES,
            'canSetTargets' => auth()->user()->can('mmd.set_targets'),
            'weekStart' => $day->startOfWeek(),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — MMD']);
    }

    /**
     * Target versus actual, computed by the domain calculator.
     *
     * @return array<int, array<string, mixed>>
     */
    private function targetComparisons(Customer $customer): array
    {
        $calculator = app(TargetVsActualCalculator::class);

        return MmdTarget::query()
            ->whereIn('enrollment_id', $customer->enrollments()->select('id'))
            ->orderByDesc('period_start')
            ->limit(12)
            ->get()
            ->map(fn (MmdTarget $target): array => [
                'target' => $target,
                'comparison' => $calculator->compare($target, $customer),
            ])
            ->all();
    }

    /**
     * @return array<string, float|null>
     */
    private function cleanFigures(): array
    {
        $clean = [];

        foreach (MmdEntry::METRICS as $metric) {
            $value = $this->figures[$metric] ?? null;
            $clean[$metric] = ($value === null || $value === '') ? null : (float) $value;
        }

        return $clean;
    }

    private function resetFigures(): void
    {
        $this->figures = array_fill_keys(MmdEntry::METRICS, null);
    }

    /**
     * Attribution only. MMD data belongs to the BUSINESS and outlives any one
     * programme run, so the enrolment records who was running the programme
     * when the figure was entered - it is not the owner.
     */
    private function attributionEnrollment(Customer $customer): ?Enrollment
    {
        $id = $customer->enrollments()->where('status', 'enrolled')->value('id');

        return $id === null ? null : Enrollment::query()->find($id);
    }

    private function enrollmentInWorkspace(int $enrollmentId): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }
}
