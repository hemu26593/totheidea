<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Turns config/authorization.php into an executable assertion.
 *
 * This is the highest-value test in the authorization suite: it proves the
 * seeded grants and the specification are identical, in both directions. A
 * permission added without a deliberate decision about each role fails here
 * immediately rather than shipping silently.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    /**
     * @return array<int, string>
     */
    private function allPermissions(): array
    {
        return array_merge(...array_values(config('authorization.permissions')));
    }

    #[Test]
    public function super_admin_holds_every_permission(): void
    {
        $superAdmin = $this->superAdmin();

        foreach ($this->allPermissions() as $permission) {
            $this->assertTrue(
                $superAdmin->can($permission),
                "Super Admin should hold [{$permission}]."
            );
        }
    }

    #[Test]
    public function super_admin_holds_permissions_that_do_not_exist_yet(): void
    {
        // Granted through Gate::before rather than stored grants, so a
        // permission added next year is covered without a reseed.
        $this->assertTrue($this->superAdmin()->can('some.future.permission'));
    }

    #[Test]
    public function admin_grants_match_the_specification_exactly(): void
    {
        $admin = $this->admin();
        $expected = config('authorization.roles.'.UserRole::Admin->value);

        foreach ($this->allPermissions() as $permission) {
            $shouldHave = in_array($permission, $expected, true);

            $this->assertSame(
                $shouldHave,
                $admin->can($permission),
                $shouldHave
                    ? "Admin should hold [{$permission}]."
                    : "Admin should NOT hold [{$permission}]."
            );
        }
    }

    #[Test]
    public function staff_grants_match_the_specification_exactly(): void
    {
        $staff = $this->staff();
        $expected = config('authorization.roles.'.UserRole::Staff->value);

        foreach ($this->allPermissions() as $permission) {
            $shouldHave = in_array($permission, $expected, true);

            $this->assertSame(
                $shouldHave,
                $staff->can($permission),
                $shouldHave
                    ? "Staff should hold [{$permission}]."
                    : "Staff should NOT hold [{$permission}]."
            );
        }
    }

    #[Test]
    public function admin_is_denied_the_four_governance_permissions(): void
    {
        $admin = $this->admin();

        foreach (['users.delete', 'roles.manage', 'settings.manage', 'audit.view'] as $permission) {
            $this->assertFalse($admin->can($permission), "Admin must not hold [{$permission}].");
        }
    }

    #[Test]
    public function staff_is_denied_every_destructive_and_export_permission(): void
    {
        $staff = $this->staff();

        $denied = [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'users.assign_role', 'roles.manage',
            'customers.create', 'customers.archive',
            'batches.create', 'batches.edit', 'batches.archive',
            'sessions.archive',
            'forms.create', 'forms.edit', 'forms.publish', 'forms.archive',
            'assignments.archive',
            'reports.export',
            'ai.forms.generate', 'ai.forms.approve', 'ai.analysis.generate',
            'settings.manage', 'audit.view',
        ];

        foreach ($denied as $permission) {
            $this->assertFalse($staff->can($permission), "Staff must not hold [{$permission}].");
        }
    }

    #[Test]
    public function customer_deletion_is_not_a_permission(): void
    {
        // Customer removal is archival (ADR-011). If this permission ever
        // appears, the decision to reintroduce hard deletion was not reviewed.
        $this->assertNotContains('customers.delete', $this->allPermissions());
    }

    #[Test]
    public function exactly_three_roles_exist(): void
    {
        $this->assertSame(
            ['admin', 'staff', 'super-admin'],
            Role::query()->orderBy('name')->pluck('name')->all()
        );
    }
}
