<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Trackers\DayPlanService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE DAY PLAN. One day, its tasks, and what carried forward into it.
 *
 * DAY PLAN IS NOT THE TIME GRID AND IS NOT THE ACTION PLAN. They are three
 * separate trackers on three separate screens:
 *
 *   - Day Plan is TODAY's tasks, with time slots.
 *   - Time Grid is STRATEGIC allocation across Q1-Q4 of a year.
 *   - Action Plan is OUTSTANDING work with a due date and an owner.
 *
 * Nothing here merges them, and no field is shared between them.
 *
 * CARRY-FORWARD IS NOT REIMPLEMENTED HERE. An unfinished task moves to the
 * next day through DayPlanService::carryForward, run by a scheduled job. This
 * screen shows the chain - where a task came from and how many days it has
 * slipped - and never creates a carried item itself.
 *
 * G / C / M are carried verbatim from the client's own worksheet. Their
 * meaning is an open decision, so the columns are labelled exactly "G", "C"
 * and "M" and no expansion is invented.
 */
class DayPlan extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    #[Url]
    public string $date = '';

    #[Url]
    public ?int $enrollmentId = null;

    public bool $adding = false;

    public string $task = '';

    public string $plannedStart = '';

    public string $plannedEnd = '';

    public string $g = '';

    public string $c = '';

    public string $m = '';

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);

        $this->date = $this->date ?: now()->toDateString();
        $this->enrollmentId ??= $customer->enrollments()->where('status', 'enrolled')->value('id')
            ?? $customer->enrollments()->value('id');
    }

    public function startAdding(): void
    {
        $this->authorize('create', DayPlanItem::class);

        $this->reset(['task', 'plannedStart', 'plannedEnd', 'g', 'c', 'm']);
        $this->adding = true;
    }

    public function add(DayPlanService $dayPlans): void
    {
        $this->authorize('create', DayPlanItem::class);

        $this->validate([
            'task' => ['required', 'string', 'max:500'],
            'date' => ['required', 'date'],
            'plannedStart' => ['nullable', 'date_format:H:i'],
            'plannedEnd' => ['nullable', 'date_format:H:i'],
            'g' => ['nullable', 'string', 'max:60'],
            'c' => ['nullable', 'string', 'max:60'],
            'm' => ['nullable', 'string', 'max:60'],
        ]);

        $enrollment = $this->enrollment();

        $saved = $this->runGuarded(function () use ($dayPlans, $enrollment): void {
            $dayPlans->create(
                enrollment: $enrollment,
                planDate: $this->date,
                task: $this->task,
                actor: auth()->user(),
                plannedStart: $this->plannedStart ?: null,
                plannedEnd: $this->plannedEnd ?: null,
                g: $this->g ?: null,
                c: $this->c ?: null,
                m: $this->m ?: null,
            );
        }, 'Task added.');

        if ($saved) {
            $this->adding = false;
        }
    }

    public function complete(int $itemId, DayPlanService $dayPlans): void
    {
        $item = $this->itemInWorkspace($itemId);

        $this->authorize('complete', $item);

        $this->runGuarded(
            fn () => $dayPlans->complete($item, null, auth()->user()),
            'Task completed.',
        );
    }

    /**
     * Reopen a completed task.
     *
     * Goes through DayPlanService::update rather than touching the model, so
     * the ownership and actor rules the service holds still apply.
     */
    public function reopen(int $itemId, DayPlanService $dayPlans): void
    {
        $item = $this->itemInWorkspace($itemId);

        $this->authorize('update', $item);

        $this->runGuarded(
            fn () => $dayPlans->update($item, ['status' => DayPlanItem::STATUS_PLANNED], auth()->user()),
            'Task reopened.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();
        $enrollment = $this->enrollmentId ? $this->enrollment() : null;

        return view('livewire.customers.day-plan', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            'items' => $enrollment === null
                ? collect()
                : app(DayPlanService::class)->forDay($enrollment, $this->date)->load('carriedFrom'),
            'slipCounts' => $enrollment === null ? [] : $this->slipCounts($enrollment),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Day Plan']);
    }

    /**
     * How far each task has slipped, asked of the service rather than counted
     * here: the carry chain is the service's to walk.
     *
     * @return array<int, int>
     */
    private function slipCounts(Enrollment $enrollment): array
    {
        $service = app(DayPlanService::class);

        return $service->forDay($enrollment, $this->date)
            ->mapWithKeys(fn (DayPlanItem $item): array => [$item->getKey() => $service->slipCount($item)])
            ->all();
    }

    private function enrollment(): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($this->enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }

    private function itemInWorkspace(int $itemId): DayPlanItem
    {
        $item = DayPlanItem::query()->findOrFail($itemId);

        $this->assertOwnedByWorkspace((int) $item->customer_id);

        return $item;
    }
}
