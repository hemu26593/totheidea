<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Exceptions\AuthorizationRuleException;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Requirements 13-16: the privilege-escalation boundary (ADR-012).
 *
 * Each test asserts both the refusal AND the resulting database state — a 403
 * that still wrote the row would be a passing test and a live vulnerability.
 */
class RoleAssignmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->users = app(UserService::class);
    }

    /** Requirement 13 */
    #[Test]
    public function admin_cannot_assign_the_super_admin_role(): void
    {
        $admin = $this->admin();
        $target = $this->staff();

        $this->assertFalse($this->users->canAssignRole($admin, UserRole::SuperAdmin));
        $this->assertFalse($admin->can('assignRole', [$target, UserRole::SuperAdmin]));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->assignRole($target, UserRole::SuperAdmin, $admin);
        } finally {
            // The escalation must not have happened.
            $this->assertSame(UserRole::Staff, $target->fresh()->role());
        }
    }

    #[Test]
    public function admin_cannot_escalate_their_own_role(): void
    {
        $admin = $this->admin();

        $this->assertFalse($admin->can('assignRole', [$admin, UserRole::SuperAdmin]));
        // A user may never change their own role at any rank.
        $this->assertFalse($admin->can('assignRole', [$admin, UserRole::Admin]));

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->assignRole($admin, UserRole::SuperAdmin, $admin);
        } finally {
            $this->assertSame(UserRole::Admin, $admin->fresh()->role());
        }
    }

    #[Test]
    public function admin_can_assign_admin_and_staff(): void
    {
        $admin = $this->admin();

        $this->assertTrue($this->users->canAssignRole($admin, UserRole::Admin));
        $this->assertTrue($this->users->canAssignRole($admin, UserRole::Staff));

        $this->assertEqualsCanonicalizing(
            [UserRole::Admin, UserRole::Staff],
            $this->users->assignableRoles($admin),
        );
    }

    /** Requirement 14 */
    #[Test]
    public function admin_cannot_modify_a_super_admin(): void
    {
        $admin = $this->admin();
        $superAdmin = $this->superAdmin();

        $this->assertFalse($admin->can('update', $superAdmin));
        $this->assertFalse($admin->can('deactivate', $superAdmin));
        $this->assertFalse($admin->can('delete', $superAdmin));
        $this->assertFalse($admin->can('assignRole', [$superAdmin, UserRole::Staff]));
    }

    #[Test]
    public function admin_cannot_demote_a_super_admin(): void
    {
        $admin = $this->admin();
        $superAdmin = $this->superAdmin();
        $this->superAdmin(); // A second one, so the last-Super-Admin guard is not what refuses.

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->assignRole($superAdmin, UserRole::Staff, $admin);
        } finally {
            $this->assertSame(UserRole::SuperAdmin, $superAdmin->fresh()->role());
        }
    }

    #[Test]
    public function admin_cannot_deactivate_a_super_admin(): void
    {
        $admin = $this->admin();
        $superAdmin = $this->superAdmin();
        $this->superAdmin();

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->deactivate($superAdmin, $admin);
        } finally {
            $this->assertTrue($superAdmin->fresh()->is_active);
        }
    }

    #[Test]
    public function admin_cannot_delete_a_super_admin(): void
    {
        $admin = $this->admin();
        $superAdmin = $this->superAdmin();
        $this->superAdmin();

        $this->expectException(AuthorizationRuleException::class);

        try {
            $this->users->delete($superAdmin, $admin);
        } finally {
            $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
        }
    }

    /** Requirement 15 */
    #[Test]
    public function staff_cannot_assign_any_role(): void
    {
        $staff = $this->staff();
        $target = $this->staff();

        $this->assertSame([], $this->users->assignableRoles($staff));

        foreach (UserRole::cases() as $role) {
            $this->assertFalse($this->users->canAssignRole($staff, $role));
            $this->assertFalse($staff->can('assignRole', [$target, $role]));
        }
    }

    /** Requirement 16 */
    #[Test]
    public function super_admin_can_assign_every_role(): void
    {
        $superAdmin = $this->superAdmin();
        $target = $this->staff();

        $this->assertEqualsCanonicalizing(UserRole::cases(), $this->users->assignableRoles($superAdmin));

        $this->users->assignRole($target, UserRole::Admin, $superAdmin);
        $this->assertSame(UserRole::Admin, $target->fresh()->role());

        $this->users->assignRole($target, UserRole::SuperAdmin, $superAdmin);
        $this->assertSame(UserRole::SuperAdmin, $target->fresh()->role());
    }

    #[Test]
    public function assigning_a_role_replaces_rather_than_accumulates(): void
    {
        $superAdmin = $this->superAdmin();
        $target = $this->staff();

        $this->users->assignRole($target, UserRole::Admin, $superAdmin);

        $this->assertSame(['admin'], $target->fresh()->roles->pluck('name')->all());
    }
}
