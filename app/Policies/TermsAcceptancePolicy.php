<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TermsAcceptance;
use App\Models\User;

/**
 * Authorization for terms acceptances.
 *
 * An acceptance is a legal record: it can be read, never edited or removed.
 */
class TermsAcceptancePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, TermsAcceptance $acceptance): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('customers.edit');
    }

    public function update(User $actor, TermsAcceptance $acceptance): bool
    {
        return false;
    }

    public function delete(User $actor, TermsAcceptance $acceptance): bool
    {
        return false;
    }
}
