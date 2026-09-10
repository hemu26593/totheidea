<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerContact;
use App\Models\User;

/**
 * Authorization for customer contacts.
 *
 * Contacts are part of the customer record, so they follow customers.*
 * rather than carrying their own permission group.
 */
class CustomerContactPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, CustomerContact $contact): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('customers.edit');
    }

    public function update(User $actor, CustomerContact $contact): bool
    {
        return $actor->can('customers.edit');
    }

    public function archive(User $actor, CustomerContact $contact): bool
    {
        return $actor->can('customers.edit');
    }

    /**
     * A contact that received reminders must stay resolvable from the
     * dispatch log, so contacts are archived rather than deleted.
     */
    public function delete(User $actor, CustomerContact $contact): bool
    {
        return false;
    }
}
