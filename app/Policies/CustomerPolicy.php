<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Authorization for customer records.
 *
 * delete() is listed in config('authorization.guarded_abilities'), so
 * Gate::before does NOT short-circuit it for a Super Admin - which is what
 * makes the refusal below absolute rather than advisory.
 */
class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('customers.create');
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->can('customers.edit');
    }

    public function archive(User $actor, Customer $customer): bool
    {
        return $actor->can('customers.archive');
    }

    /**
     * Never. Customers are archived (ADR-011) so that historical
     * customer-owned data stays available. There is no customers.delete
     * permission, and no role - including Super Admin - can obtain one.
     */
    public function delete(User $actor, Customer $customer): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Customer $customer): bool
    {
        return false;
    }

    public function restore(User $actor, Customer $customer): bool
    {
        return false;
    }
}
