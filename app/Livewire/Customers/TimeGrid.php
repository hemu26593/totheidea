<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Trackers\TimeGridService;
use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\TimeGridEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE TIME GRID. Strategic allocation across Q1-Q4 of a year.
 *
 * NOT THE DAY PLAN. The Day Plan is today's task list with time slots; this is
 * where a year's effort is intended to go, by activity and by quarter. They
 * share no field and no screen, and merging them would lose the distinction
 * the client's own worksheets draw.
 *
 * PLANNED AND ACTUAL ARE BOTH SHOWN, AND NEITHER IS SCORED. The variance
 * between them is a fact the reader can see; this screen computes no
 * adherence rating, no percentage and no verdict, because none has been
 * defined.
 */
class TimeGrid extends Component
{
    use AuthorizesCustomerWorkspace;
    use ReportsDomainFailures;

    #[Url]
    public int $year = 0;

    #[Url]
    public ?int $enrollmentId = null;

    public bool $adding = false;

    public int $quarter = 1;

    public string $activity = '';

    public ?float $plannedHours = null;

    public ?float $actualHours = null;

    public ?int $editingId = null;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);

        $this->year = $this->year ?: (int) now()->year;
        $this->enrollmentId ??= $customer->enrollments()->where('status', 'enrolled')->value('id')
            ?? $customer->enrollments()->value('id');
    }

    public function startAdding(int $quarter): void
    {
        $this->authorize('create', TimeGridEntry::class);

        $this->reset(['activity', 'plannedHours', 'actualHours', 'editingId']);
        $this->quarter = $quarter;
        $this->adding = true;
    }

    public function startEditing(int $entryId): void
    {
        $entry = $this->entryInWorkspace($entryId);

        $this->authorize('update', $entry);

        $this->editingId = $entryId;
        $this->quarter = (int) $entry->quarter;
        $this->activity = (string) $entry->activity;
        $this->plannedHours = $entry->planned_hours === null ? null : (float) $entry->planned_hours;
        $this->actualHours = $entry->actual_hours === null ? null : (float) $entry->actual_hours;
        $this->adding = true;
    }

    public function save(TimeGridService $grid): void
    {
        $this->validate([
            'quarter' => ['required', 'integer', 'in:1,2,3,4'],
            'activity' => ['required', 'string', 'max:200'],
            'plannedHours' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'actualHours' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        if ($this->editingId !== null) {
            $entry = $this->entryInWorkspace($this->editingId);

            $this->authorize('update', $entry);

            // Amending is audited by the service: the planned figure a quarter
            // was reviewed against must survive being revised.
            $saved = $this->runGuarded(
                fn () => $grid->amend($entry, $this->plannedHours, $this->actualHours, auth()->user()),
                'Entry amended.',
            );
        } else {
            $this->authorize('create', TimeGridEntry::class);

            $enrollment = $this->enrollment();

            $saved = $this->runGuarded(function () use ($grid, $enrollment): void {
                $grid->record(
                    $enrollment,
                    $this->year,
                    $this->quarter,
                    $this->activity,
                    $this->plannedHours,
                    $this->actualHours,
                    auth()->user(),
                );
            }, 'Entry recorded.');
        }

        if ($saved) {
            $this->adding = false;
            $this->editingId = null;
        }
    }

    public function render(): View
    {
        $customer = $this->customer();
        $enrollment = $this->enrollmentId ? $this->enrollment() : null;

        $entries = $enrollment === null
            ? collect()
            : app(TimeGridService::class)->gridFor($enrollment, $this->year);

        return view('livewire.customers.time-grid', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'enrollments' => $customer->enrollments()->with('batch:id,name,code')->get(),
            'quarters' => TimeGridEntry::QUARTERS,
            'byQuarter' => $entries->groupBy('quarter'),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Time Grid']);
    }

    private function enrollment(): Enrollment
    {
        $enrollment = Enrollment::query()->findOrFail($this->enrollmentId);

        $this->assertOwnedByWorkspace((int) $enrollment->customer_id);

        return $enrollment;
    }

    /**
     * A time-grid entry carries no customer_id of its own - it is
     * enrolment-owned - so ownership is resolved through the enrolment.
     */
    private function entryInWorkspace(int $entryId): TimeGridEntry
    {
        $entry = TimeGridEntry::query()->with('enrollment')->findOrFail($entryId);

        $this->assertOwnedByWorkspace((int) ($entry->enrollment?->customer_id));

        return $entry;
    }
}
