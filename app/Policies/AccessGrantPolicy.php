<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessGrant;
use App\Models\User;

/**
 * Authorization for scoped external access grants.
 *
 * Issuing a grant creates the only unauthenticated write path in the system,
 * so it carries its own permission rather than riding on customers.edit.
 *
 * Note what is absent: there is no redeem() ability here. Redemption is
 * authorised by the GRANT's own scope, not by a user's permissions, and it
 * belongs to Phase 5.
 */
class AccessGrantPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, AccessGrant $grant): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('access_grants.issue');
    }

    public function revoke(User $actor, AccessGrant $grant): bool
    {
        return $actor->can('access_grants.revoke');
    }

    /**
     * A grant is retained after expiry as evidence of what was permitted.
     * Revocation is state, not deletion.
     */
    public function delete(User $actor, AccessGrant $grant): bool
    {
        return false;
    }
}
