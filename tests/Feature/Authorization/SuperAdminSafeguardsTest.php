<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Exceptions\AuthorizationRuleException;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Requirements 17-19: lockout protection (ADR-012).
 *
 * These exercise the guarded abilities — the ones Gate::before deliberately
 * does NOT short-circuit. A naive Gate::before returning true would make every
 * assertion in this file fail, which is the point of testing them.
 */
class SuperAdminSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->users = app(UserService::class);
    }

    /** Requirement 19 */
    #[Test]
    public function a_super_admin_cannot_delete_their_own_account(): void
    {
        $superAdmin = $this->superAdmin();
        $this->superAdmin(); // Not the last one — self-deletion is refused regardless.

        $this->assertFalse($superAdmin->can('delete', $superAdmin));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->delete($superAdmin, $superAdmin);
        } finally {
            $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
        }
    }

    #[Test]
    public function a_super_admin_cannot_deactivate_their_own_account(): void
    {
        $superAdmin = $this->superAdmin();
        $this->superAdmin();

        $this->assertFalse($superAdmin->can('deactivate', $superAdmin));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->deactivate($superAdmin, $superAdmin);
        } finally {
            $this->assertTrue($superAdmin->fresh()->is_active);
        }
    }

    #[Test]
    public function nobody_can_deactivate_their_own_account(): void
    {
        // Not a Super Admin rule — it applies at every rank.
        foreach ([$this->admin(), $this->staff()] as $user) {
            $this->assertFalse($user->can('deactivate', $user));
        }
    }

    #[Test]
    public function a_super_admin_cannot_remove_their_own_super_admin_role(): void
    {
        $superAdmin = $this->superAdmin();
        $this->superAdmin();

        $this->assertFalse($superAdmin->can('assignRole', [$superAdmin, UserRole::Staff]));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->assignRole($superAdmin, UserRole::Staff, $superAdmin);
        } finally {
            $this->assertSame(UserRole::SuperAdmin, $superAdmin->fresh()->role());
        }
    }

    /**
     * Isolate the last-active-Super-Admin guard from the self-action rule.
     *
     * Only a Super Admin outranks a Super Admin, so any *active* Super Admin
     * actor would itself mean the target is not the last active one. The actor
     * here is therefore a deactivated Super Admin: it still carries rank 3, so
     * the rank check passes and the invariant guard is what refuses.
     *
     * That is not a reachable HTTP path (a deactivated user cannot sign in),
     * which is the point — this guard exists for console callers, queued jobs,
     * and concurrent requests, where no policy has run.
     */
    private function lastActiveSuperAdminAndDeactivatedActor(): array
    {
        $actor = $this->superAdmin();
        $onlyActive = $this->superAdmin();

        $this->users->deactivate($actor, $onlyActive);

        return [$onlyActive->fresh(), $actor->fresh()];
    }

    /** Requirement 17 */
    #[Test]
    public function the_last_super_admin_cannot_be_demoted(): void
    {
        [$onlyActive, $actor] = $this->lastActiveSuperAdminAndDeactivatedActor();

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->assignRole($onlyActive, UserRole::Admin, $actor);
        } finally {
            $this->assertSame(UserRole::SuperAdmin, $onlyActive->fresh()->role());
        }
    }

    /** Requirement 18 */
    #[Test]
    public function the_last_super_admin_cannot_be_deactivated(): void
    {
        [$onlyActive, $actor] = $this->lastActiveSuperAdminAndDeactivatedActor();

        $this->assertFalse($actor->can('deactivate', $onlyActive));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->deactivate($onlyActive, $actor);
        } finally {
            $this->assertTrue($onlyActive->fresh()->is_active);
        }
    }

    #[Test]
    public function the_last_super_admin_cannot_be_deleted(): void
    {
        [$onlyActive, $actor] = $this->lastActiveSuperAdminAndDeactivatedActor();

        $this->assertFalse($actor->can('delete', $onlyActive));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->delete($onlyActive, $actor);
        } finally {
            $this->assertDatabaseHas('users', ['id' => $onlyActive->id]);
        }
    }

    #[Test]
    public function a_super_admin_can_be_demoted_while_another_active_one_remains(): void
    {
        $actor = $this->superAdmin();
        $target = $this->superAdmin();

        $this->users->assignRole($target, UserRole::Admin, $actor);

        $this->assertSame(UserRole::Admin, $target->fresh()->role());
    }

    #[Test]
    public function an_inactive_super_admin_does_not_satisfy_the_invariant(): void
    {
        $active = $this->superAdmin();
        $inactive = $this->superAdmin(['is_active' => false]);

        // A deactivated Super Admin exists, but the invariant counts only
        // active ones — so $active is still the last that matters.
        $this->assertFalse($inactive->can('deactivate', $active));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->deactivate($active, $inactive);
        } finally {
            $this->assertTrue($active->fresh()->is_active);
        }
    }

    #[Test]
    public function gate_before_does_not_bypass_the_guarded_abilities(): void
    {
        // Regression test for the subtle failure mode: if Gate::before returned
        // true unconditionally for Super Admins, every safeguard above would be
        // silently unreachable.
        $superAdmin = $this->superAdmin();

        $this->assertTrue($superAdmin->can('settings.manage'), 'Ordinary abilities are granted.');
        $this->assertFalse($superAdmin->can('delete', $superAdmin), 'Guarded abilities fall through to the policy.');

        $this->assertSame(
            ['delete', 'deactivate', 'assignRole'],
            config('authorization.guarded_abilities'),
        );
    }
}
