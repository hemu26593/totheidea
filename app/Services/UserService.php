<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Exceptions\AuthorizationRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * All write operations on internal user accounts.
 *
 * Every method here is transactional and audited. The Super Admin safeguards
 * (ADR-012) live here as well as in UserPolicy on purpose: the policy produces
 * the 403 for HTTP callers, this produces the integrity guarantee for every
 * caller, including console commands and future queued jobs.
 */
class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionInvalidator $sessions,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function create(array $attributes, UserRole $role, User $actor): User
    {
        return DB::transaction(function () use ($attributes, $role, $actor): User {
            $user = User::create($attributes);

            $user->assignRole($role->value);

            $this->audit->log(
                AuditAction::UserCreated,
                $user,
                null,
                ['name' => $user->name, 'email' => $user->email, 'role' => $role->value],
                $actor,
            );

            return $user->fresh();
        });
    }

    /**
     * Update profile attributes. Role and activation state are handled by their
     * own methods so that each carries its own safeguards and audit action.
     *
     * @param  array{name?: string, email?: string}  $attributes
     */
    public function update(User $user, array $attributes, User $actor): User
    {
        $this->assertActorOutranks($actor, $user, 'update this user');

        return DB::transaction(function () use ($user, $attributes, $actor): User {
            $before = $user->only(array_keys($attributes));

            $user->fill($attributes);
            $after = $user->only(array_keys($attributes));
            $user->save();

            $this->audit->logChanges(AuditAction::UserUpdated, $user, $before, $after, $actor);

            return $user->fresh();
        });
    }

    public function activate(User $user, User $actor): User
    {
        $this->assertActorOutranks($actor, $user, 'activate this user');

        return DB::transaction(function () use ($user, $actor): User {
            if ($user->is_active) {
                return $user;
            }

            $user->forceFill(['is_active' => true])->save();

            $this->audit->log(
                AuditAction::UserActivated,
                $user,
                ['is_active' => false],
                ['is_active' => true],
                $actor,
            );

            return $user->fresh();
        });
    }

    /**
     * Deactivate an account and terminate its live sessions.
     */
    public function deactivate(User $user, User $actor): User
    {
        if ($actor->is($user)) {
            throw AuthorizationRuleException::selfAction('deactivate your own account');
        }

        $this->assertActorOutranks($actor, $user, 'deactivate this user');

        return DB::transaction(function () use ($user, $actor): User {
            $this->assertNotLastActiveSuperAdmin($user, 'deactivate');

            if (! $user->is_active) {
                return $user;
            }

            $user->forceFill(['is_active' => false])->save();

            // Revoking access must take effect now, not when the session expires.
            $this->sessions->forUser($user);

            $this->audit->log(
                AuditAction::UserDeactivated,
                $user,
                ['is_active' => true],
                ['is_active' => false],
                $actor,
            );

            return $user->fresh();
        });
    }

    /**
     * Replace the user's role.
     *
     * V1 holds exactly one role per user, so this syncs rather than adds.
     */
    public function assignRole(User $user, UserRole $role, User $actor): User
    {
        if ($actor->is($user)) {
            throw AuthorizationRuleException::selfAction('change your own role');
        }

        $this->assertActorOutranks($actor, $user, 'change this user\'s role');

        if (! $this->canAssignRole($actor, $role)) {
            throw AuthorizationRuleException::unassignableRole($role->value);
        }

        return DB::transaction(function () use ($user, $role, $actor): User {
            $previous = $user->role();

            if ($previous === $role) {
                return $user;
            }

            // Demoting the last Super Admin is the same event as deleting them.
            if ($previous === UserRole::SuperAdmin) {
                $this->assertNotLastActiveSuperAdmin($user, 'demote');
            }

            $user->syncRoles([$role->value]);

            $this->audit->log(
                AuditAction::RoleAssigned,
                $user,
                ['role' => $previous?->value],
                ['role' => $role->value],
                $actor,
            );

            return $user->fresh();
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($actor->is($user)) {
            throw AuthorizationRuleException::selfAction('delete your own account');
        }

        $this->assertActorOutranks($actor, $user, 'delete this user');

        DB::transaction(function () use ($user, $actor): void {
            $this->assertNotLastActiveSuperAdmin($user, 'delete');

            $snapshot = [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role()?->value,
            ];

            $this->sessions->forUser($user);

            // Audit before deleting so the entry survives; actor_id is nulled on
            // actor deletion but actor_label keeps the trail readable.
            $this->audit->log(AuditAction::UserDeleted, $user, $snapshot, null, $actor);

            $user->roles()->detach();
            $user->delete();
        });
    }

    /**
     * Roles the actor is permitted to assign (ADR-012).
     *
     * The rank comparison is the security control; the UI reads this list only
     * to avoid offering options that would be rejected anyway.
     *
     * @return array<int, UserRole>
     */
    public function assignableRoles(User $actor): array
    {
        if (! $actor->can('users.assign_role')) {
            return [];
        }

        return array_values(array_filter(
            UserRole::cases(),
            fn (UserRole $role): bool => $this->canAssignRole($actor, $role),
        ));
    }

    public function canAssignRole(User $actor, UserRole $role): bool
    {
        if (! $actor->can('users.assign_role')) {
            return false;
        }

        // An actor may assign roles up to their own rank, never above it.
        // Admin (2) may therefore assign Admin and Staff, never Super Admin (3).
        return $role->rank() <= $actor->rank();
    }

    /**
     * Guard the "at least one active Super Admin" invariant.
     *
     * Runs inside the caller's transaction. lockForUpdate() serialises
     * concurrent demotions/deletions so two requests cannot each observe a
     * count of one remaining Super Admin and both proceed, leaving zero.
     *
     * NOTE: lockForUpdate() is a no-op on SQLite, which serialises writes
     * globally. This guard is therefore correct but not genuinely exercised in
     * the development environment — it must be verified against MySQL before
     * production (ADR-005 / ADR-006).
     */
    private function assertNotLastActiveSuperAdmin(User $user, string $operation): void
    {
        if (! $user->isSuperAdmin()) {
            return;
        }

        $remaining = User::query()
            ->role(UserRole::SuperAdmin->value)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->count();

        if ($remaining < 1) {
            throw AuthorizationRuleException::lastSuperAdmin($operation);
        }
    }

    private function assertActorOutranks(User $actor, User $target, string $operation): void
    {
        if (! $actor->outranksOrMatches($target)) {
            throw AuthorizationRuleException::insufficientRank($operation);
        }
    }
}
