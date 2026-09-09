<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Trackers\ActionItemService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\ActionItem;
use App\Models\Customer;
use App\Models\Enrollment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE ACTION PLAN. Outstanding work with a due date, a priority and a source.
 *
 * NOT THE DAY PLAN AND NOT THE TIME GRID. An action item is a commitment that
 * outlives a day; the Day Plan is one day's schedule; the Time Grid is a
 * year's allocation. Three trackers, three screens, no shared fields.
 *
 * SOURCE IS SHOWN WHERE THE DOMAIN RECORDS ONE. Action items can be fed from
 * an assignment. They can also, in principle, be fed from a weak skill area -
 * but ActionItemFeeder::feedWeakSkillAreas deliberately refuses, because
 * "weak" needs a threshold nobody has defined. This screen therefore shows the
 * source that exists and invents no weak-skill scoring of its own.
 */
class ActionPlan extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    #[Url]
    public string $status = 'open';

    #[Url]
    public ?int $enrollmentId = null;

    public bool $adding = false;

    public string $title = '';

    public string $description = '';

    public string $dueDate = '';

    public string $priority = ActionItem::PRIORITY_NORMAL;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);

        $this->enrollmentId ??= $customer->enrollments()->where('status', 'enrolled')->value('id')
            ?? $customer->enrollments()->value('id');
    }

    public function startAdding(): void
    {
        $this->authorize('create', ActionItem::class);

        $this->reset(['title', 'description', 'dueDate']);
        $this->priority = ActionItem::PRIORITY_NORMAL;
        $this->adding = true;
    }

    public function add(ActionItemService $items): void
    {
        $this->authorize('create', ActionItem::class);

        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'dueDate' => ['nullable', 'date'],
            'priority' => ['required', 'string', 'in:'.implode(',', ActionItem::PRIORITIES)],
        ]);

        $enrollment = $this->enrollment();

        $saved = $this->runGuarded(function () use ($items, $enrollment): void {
            $items->create(
                enrollment: $enrollment,
                title: $this->title,
                description: $this->description ?: null,
                dueDate: $this->dueDate ?: null,
                priority: $this->priority,
                actor: auth()->user(),
            );
        }, 'Action added.');

        if ($saved) {
            $this->adding = false;
        }
    }

    /**
     * Named moveTo() rather than transition(): Livewire\Component already
     * declares transition(), and shadowing a framework method with a different
     * signature is a fatal error rather than an override.
     */
    public function moveTo(int $itemId, string $status, ActionItemService $items): void
    {
        $item = $this->itemInWorkspace($itemId);

        $this->authorize($status === ActionItem::STATUS_DONE ? 'complete' : 'update', $item);

        if (! in_array($status, ActionItem::STATUSES, true)) {
            abort(422);
        }

        $this->runGuarded(
            fn () => $items->transitionTo($item, $status, auth()->user()),
            'Action updated.',
        );
    }

    public function render(): View
    {
        $customer = $this->customer();

        $items = $this->enrollmentId === null
            ? collect()
            : ActionItem::query()
                ->where('enrollment_id', $this->enrollment()->getKey())
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->with('sourceRecord')
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_date')
                ->orderBy('position')
                ->get();

        return view('livewire.customers.action-plan', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            'items' => $items,
            'statuses' => ActionItem::STATUSES,
            'priorities' => ActionItem::PRIORITIES,
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Action Plan']);
    }

    private function enrollment(): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($this->enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }

    private function itemInWorkspace(int $itemId): ActionItem
    {
        $item = ActionItem::query()->with('enrollment')->findOrFail($itemId);

        $this->assertOwnedByWorkspace((int) ($item->enrollment?->customer_id));

        return $item;
    }
}
