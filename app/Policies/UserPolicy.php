<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Authorization for internal user administration.
 *
 * delete(), deactivate() and assignRole() are listed in
 * config('authorization.guarded_abilities'), so Gate::before does NOT
 * short-circuit them for Super Admins — otherwise the self-lockout safeguards
 * would not apply to the only role able to trigger them (ADR-012).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('users.edit')
            && $actor->outranksOrMatches($target);
    }

    /**
     * Deactivation. Guarded ability — reached by Super Admins too.
     */
    public function deactivate(User $actor, User $target): bool
    {
        // Nobody deactivates themselves, whatever their role.
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->outranksOrMatches($target)) {
            return false;
        }

        if (! $this->hasUserEditCapability($actor)) {
            return false;
        }

        // Never strand the system without an active Super Admin.
        return ! $this->isLastActiveSuperAdmin($target);
    }

    public function activate(User $actor, User $target): bool
    {
        return $this->hasUserEditCapability($actor)
            && $actor->outranksOrMatches($target);
    }

    /**
     * Deletion. Guarded ability.
     */
    public function delete(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->outranksOrMatches($target)) {
            return false;
        }

        // users.delete is Super Admin only; Gate::before does not cover this
        // ability, so the permission is checked explicitly.
        if (! $actor->hasPermissionTo('users.delete') && ! $actor->isSuperAdmin()) {
            return false;
        }

        return ! $this->isLastActiveSuperAdmin($target);
    }

    /**
     * Role assignment. Guarded ability, and the main privilege-escalation
     * boundary in the system.
     */
    public function assignRole(User $actor, User $target, ?UserRole $role = null): bool
    {
        // A user may never alter their own role, at any rank.
        if ($actor->is($target)) {
            return false;
        }

        if (! $this->hasAssignRoleCapability($actor)) {
            return false;
        }

        // Cannot act on someone who outranks you — this is what stops an Admin
        // touching a Super Admin's role.
        if (! $actor->outranksOrMatches($target)) {
            return false;
        }

        // Cannot grant a role above your own rank.
        if ($role !== null && $role->rank() > $actor->rank()) {
            return false;
        }

        // Demoting the last active Super Admin is refused.
        if ($target->isSuperAdmin()
            && $role !== UserRole::SuperAdmin
            && $this->isLastActiveSuperAdmin($target)) {
            return false;
        }

        return true;
    }

    /**
     * Super Admin holds every permission through Gate::before, but these
     * guarded abilities bypass it — so capability is resolved explicitly.
     */
    private function hasUserEditCapability(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasPermissionTo('users.edit');
    }

    private function hasAssignRoleCapability(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasPermissionTo('users.assign_role');
    }

    private function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $target->isSuperAdmin() || ! $target->is_active) {
            return false;
        }

        return User::query()
            ->role(UserRole::SuperAdmin->value)
            ->where('is_active', true)
            ->whereKeyNot($target->getKey())
            ->doesntExist();
    }
}
