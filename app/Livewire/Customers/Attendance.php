<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Models\Customer;
use App\Models\SessionAttendance;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * This business's attendance history.
 *
 * RAW STATUSES AND COUNTS. No percentage appears on this page. The weighting
 * of `late` and `excused` is an open client decision and AttendanceWeighting
 * has no implementation bound, so there is no authoritative figure to show -
 * and a made-up one in front of a participant would be worse than none.
 */
class Attendance extends Component
{
    use AuthorizesCustomerWorkspace;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function render(): View
    {
        $customer = $this->customer();

        $marks = SessionAttendance::query()
            ->whereIn('enrollment_id', $customer->enrollments()->select('id'))
            ->with([
                'sessionInstance.batch:id,name,code',
                'sessionInstance.sessionTemplate:id,sequence,title',
                'markedBy:id,name',
            ])
            ->get()
            ->sortBy(fn (SessionAttendance $m) => $m->sessionInstance?->planned_date);

        return view('livewire.customers.attendance', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'marks' => $marks,
            'statuses' => SessionAttendance::STATUSES,
            'counts' => $marks->groupBy('status')->map->count(),
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Attendance']);
    }
}
