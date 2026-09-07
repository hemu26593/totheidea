<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The BMP domain permissions added in Phase 0.
 *
 * PermissionMatrixTest already proves the seeded grants and
 * config/authorization.php agree in both directions for every permission, so
 * these tests do not repeat that. They pin the decisions that would be easy
 * to erode: which abilities are Admin-only, and that approval stays guarded.
 */
class DomainPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 21 permissions Step 3C identified as genuine gaps.
     *
     * @var array<int, string>
     */
    private const DOMAIN_PERMISSIONS = [
        'access_grants.issue',
        'access_grants.revoke',
        'documents.manage',
        'notes.manage',
        'notes.view_internal',
        'day_plans.view',
        'day_plans.manage',
        'time_grid.manage',
        'mmd.view',
        'mmd.manage',
        'mmd.set_targets',
        'fund_plans.view',
        'fund_plans.manage',
        'fund_plans.approve',
        'action_items.view',
        'action_items.manage',
        'positions.manage',
        'hr_policies.manage',
        'hr_policies.publish',
        'notifications.view',
        'notifications.manage',
    ];

    /**
     * Approval-shaped abilities. V1 keeps these with Admin.
     *
     * @var array<int, string>
     */
    private const ADMIN_ONLY = [
        'mmd.set_targets',
        'fund_plans.approve',
        'hr_policies.publish',
    ];

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
    public function every_domain_permission_is_declared_in_the_source_of_truth(): void
    {
        foreach (self::DOMAIN_PERMISSIONS as $permission) {
            $this->assertContains(
                $permission,
                $this->allPermissions(),
                "[{$permission}] must be declared in config/authorization.php."
            );
        }
    }

    #[Test]
    public function every_domain_permission_is_seeded_into_the_database(): void
    {
        foreach (self::DOMAIN_PERMISSIONS as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->exists(),
                "[{$permission}] must be seeded."
            );
        }
    }

    #[Test]
    public function admin_holds_every_domain_permission(): void
    {
        $admin = $this->admin();

        foreach (self::DOMAIN_PERMISSIONS as $permission) {
            $this->assertTrue($admin->can($permission), "Admin should hold [{$permission}].");
        }
    }

    #[Test]
    public function admin_holds_the_three_approval_abilities(): void
    {
        $admin = $this->admin();

        foreach (self::ADMIN_ONLY as $permission) {
            $this->assertTrue($admin->can($permission), "Admin should hold [{$permission}].");
        }
    }

    #[Test]
    public function staff_is_denied_the_three_approval_abilities(): void
    {
        $staff = $this->staff();

        foreach (self::ADMIN_ONLY as $permission) {
            $this->assertFalse($staff->can($permission), "Staff must not hold [{$permission}].");
        }
    }

    #[Test]
    public function staff_may_operate_the_trackers_it_is_responsible_for(): void
    {
        $staff = $this->staff();

        foreach ([
            'day_plans.view', 'day_plans.manage',
            'time_grid.manage',
            'mmd.view', 'mmd.manage',
            'fund_plans.view', 'fund_plans.manage',
            'action_items.view', 'action_items.manage',
            'access_grants.issue', 'access_grants.revoke',
            'documents.manage', 'notes.manage', 'notes.view_internal',
            'positions.manage', 'hr_policies.manage',
            'notifications.view',
        ] as $permission) {
            $this->assertTrue($staff->can($permission), "Staff should hold [{$permission}].");
        }
    }

    #[Test]
    public function staff_cannot_change_what_a_customer_receives(): void
    {
        // Retry and suppression alter delivery to a customer contact, so they
        // stay with Admin (Step 3C section 7.3).
        $this->assertFalse($this->staff()->can('notifications.manage'));
    }

    #[Test]
    public function super_admin_holds_every_domain_permission(): void
    {
        $superAdmin = $this->superAdmin();

        foreach (self::DOMAIN_PERMISSIONS as $permission) {
            $this->assertTrue($superAdmin->can($permission), "Super Admin should hold [{$permission}].");
        }
    }

    #[Test]
    public function approve_is_a_guarded_ability(): void
    {
        // ADR-014: an AI generation cannot be approved by the user who
        // generated it. That check lives in the policy, so Gate::before must
        // not short-circuit it - not even for a Super Admin.
        $this->assertContains('approve', config('authorization.guarded_abilities'));
    }

    #[Test]
    public function gate_before_does_not_auto_grant_the_approve_ability(): void
    {
        $this->assertFalse(
            $this->superAdmin()->can('approve'),
            'Gate::before must fall through for [approve] so the self-approval check applies.'
        );
    }

    #[Test]
    public function the_existing_guarded_abilities_are_unchanged(): void
    {
        foreach (['delete', 'deactivate', 'assignRole'] as $ability) {
            $this->assertContains($ability, config('authorization.guarded_abilities'));
        }
    }

    #[Test]
    public function no_consultant_or_customer_role_was_introduced(): void
    {
        $this->assertSame(
            ['admin', 'staff', 'super-admin'],
            Role::query()->orderBy('name')->pluck('name')->all()
        );

        $this->assertSame(['super-admin', 'admin', 'staff'], UserRole::values());
    }
}
