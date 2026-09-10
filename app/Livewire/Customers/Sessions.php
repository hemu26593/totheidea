<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Livewire\Concerns\AuthorizesCustomerWorkspace;
use App\Models\Customer;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Sessions 1-6 as this business experiences them.
 *
 * A session belongs to a BATCH, not to a customer, so this view is the
 * intersection: the sessions of the batches this business is enrolled in, with
 * its own attendance mark against each. Sessions 7-18 do not exist in the
 * curriculum and are not fabricated here.
 */
class Sessions extends Component
{
    use AuthorizesCustomerWorkspace;

    public function mount(Customer $customer): void
    {
        $this->mountCustomer($customer);
    }

    public function render(): View
    {
        $customer = $this->customer();
        $enrollments = $customer->enrollments()->with('batch:id,name,code')->get();

        $sessions = SessionInstance::query()
            ->whereIn('batch_id', $enrollments->pluck('batch_id')->filter())
            ->with(['batch:id,name,code', 'sessionTemplate:id,sequence,title,theme'])
            ->withCount('assignmentInstances')
            ->orderBy('planned_date')
            ->get();

        // This business's own marks only. Scoped by enrolment, so another
        // participant's attendance is not reachable from here.
        $marks = SessionAttendance::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('session_instance_id');

        return view('livewire.customers.sessions', [
            'customer' => $customer,
            'tabs' => $this->workspaceTabs($customer),
            'sessions' => $sessions,
            'marks' => $marks,
        ])->layout('components.layouts.app', ['title' => $customer->name.' — Sessions']);
    }
}
