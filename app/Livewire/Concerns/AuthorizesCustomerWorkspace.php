<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Customer;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;

/**
 * The customer workspace: one customer, resolved server-side, on every screen
 * that hangs off /customers/{customer}.
 *
 * WHY THE ID IS LOCKED AND RE-QUERIED. A Livewire component's public state
 * round-trips through the browser, so a customer id held as ordinary public
 * state is a customer id the client can edit. #[Locked] refuses the tampered
 * payload, and customer() re-reads the record from the database on every
 * request rather than trusting a hydrated model - so a swapped id cannot
 * become a swapped workspace.
 *
 * AUTHORIZATION RUNS TWICE, deliberately. mountCustomer() authorizes the
 * customer on arrival; each component's own actions authorize the specific
 * ability they perform. A public Livewire method is an HTTP endpoint whatever
 * the rendered page offers, so mount-time authorization alone would leave
 * every action open.
 */
trait AuthorizesCustomerWorkspace
{
    #[Locked]
    public int $customerId;

    /**
     * Resolve and authorize the workspace this component belongs to.
     */
    protected function mountCustomer(Customer $customer): void
    {
        $this->authorize('view', $customer);

        $this->customerId = (int) $customer->getKey();
    }

    /**
     * The customer, re-read on every request.
     */
    protected function customer(): Customer
    {
        return Customer::query()->findOrFail($this->customerId);
    }

    /**
     * Assert that a record reached through a request belongs to this
     * workspace.
     *
     * Never trust an id that arrived alongside a customer: verify the record's
     * own ownership. A 404 rather than a 403, because whether another
     * customer's record exists is itself information.
     */
    protected function assertOwnedByWorkspace(?int $recordCustomerId): void
    {
        if ($recordCustomerId === null || $recordCustomerId !== $this->customerId) {
            abort(404);
        }
    }

    /**
     * The workspace's secondary navigation.
     *
     * A tab whose permission the actor lacks is omitted; the destination
     * authorizes itself regardless, so this only prevents a dead link.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function workspaceTabs(Customer $customer): array
    {
        $definitions = [
            'overview' => ['Overview', 'customers.show', null],
            'enrollments' => ['Enrolments', 'customers.enrollments', 'customers.view'],
            'forms' => ['Forms', 'customers.forms', 'forms.view'],
            'sessions' => ['Sessions', 'customers.sessions', 'sessions.view'],
            'assignments' => ['Assignments', 'customers.assignments', 'assignments.view'],
            'attendance' => ['Attendance', 'customers.attendance', 'attendance.view'],
            'day-plan' => ['Day Plan', 'customers.day-plan', 'day_plans.view'],
            'time-grid' => ['Time Grid', 'customers.time-grid', 'time_grid.manage'],
            'mmd' => ['MMD', 'customers.mmd', 'mmd.view'],
            'fund-plan' => ['Fund Plan', 'customers.fund-plan', 'fund_plans.view'],
            'action-plan' => ['Action Plan', 'customers.action-plan', 'action_items.view'],
            'business' => ['HR & Systems', 'customers.business', 'positions.manage'],
            'documents' => ['Documents', 'customers.documents', 'documents.manage'],
            'notes' => ['Notes', 'customers.notes', 'notes.manage'],
            'reports' => ['Reports', 'customers.reports', 'reports.view'],
            'ai' => ['AI', 'customers.ai', 'ai.analysis.view'],
        ];

        $tabs = [];
        $actor = auth()->user();

        foreach ($definitions as $key => [$label, $route, $permission]) {
            if (! Route::has($route)) {
                continue;
            }

            if ($permission !== null && ! $actor?->can($permission)) {
                continue;
            }

            $tabs[$key] = ['label' => $label, 'url' => route($route, $customer)];
        }

        return $tabs;
    }
}
