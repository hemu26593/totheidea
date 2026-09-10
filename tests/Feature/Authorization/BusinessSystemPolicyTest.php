<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Phase 7 policies against the Phase 0 permission matrix.
 *
 * The line that matters is hr_policies.publish. Staff drafts; Admin puts a
 * policy in force across someone's business. Phase 7 is the first phase where
 * that line has anything to guard, and it is asserted from three directions:
 * the permission itself, the policy class, and the service.
 */
class BusinessSystemPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    // --- The Phase 0 line ------------------------------------------------------

    #[Test]
    public function staff_may_draft_a_policy_but_not_publish_it(): void
    {
        $staff = $this->staff();
        $policy = HrPolicy::factory()->create();

        $this->assertTrue($staff->can('hr_policies.manage'));
        $this->assertTrue($staff->can('create', HrPolicy::class));
        $this->assertTrue($staff->can('update', $policy));
        $this->assertTrue($staff->can('supersede', $policy));

        $this->assertFalse($staff->can('hr_policies.publish'));
        $this->assertFalse($staff->can('publish', $policy));
    }

    #[Test]
    public function admin_may_publish(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->can('hr_policies.publish'));
        $this->assertTrue($admin->can('publish', HrPolicy::factory()->create()));
    }

    #[Test]
    public function super_admin_may_publish_under_the_existing_guarded_ability_rules(): void
    {
        // 'publish' is a capability, not a self-protection safeguard, so it is
        // deliberately not among the guarded abilities - Gate::before grants
        // it, and the seeded matrix grants it too.
        $this->assertSame(
            ['delete', 'deactivate', 'assignRole', 'approve'],
            config('authorization.guarded_abilities'),
        );

        $this->assertTrue($this->superAdmin()->can('publish', HrPolicy::factory()->create()));
    }

    #[Test]
    public function the_mmd_and_fund_plan_lines_are_untouched(): void
    {
        // Phase 7 must not have moved the two lines Phase 6 relies on.
        $staff = $this->staff();

        $this->assertFalse($staff->can('mmd.set_targets'));
        $this->assertFalse($staff->can('fund_plans.approve'));
        $this->assertTrue($this->admin()->can('mmd.set_targets'));
        $this->assertTrue($this->admin()->can('fund_plans.approve'));
    }

    // --- Staff gained nothing else ------------------------------------------------

    #[Test]
    public function staff_holds_exactly_the_business_system_permissions_phase_zero_assigned(): void
    {
        $staff = $this->staff();

        // Granted in Phase 0.
        $this->assertTrue($staff->can('positions.manage'));
        $this->assertTrue($staff->can('hr_policies.manage'));

        // Withheld in Phase 0, and still withheld.
        $this->assertFalse($staff->can('hr_policies.publish'));
    }

    #[Test]
    public function no_new_business_system_permission_was_invented(): void
    {
        $declared = collect(config('authorization.permissions'))->flatten()->all();

        foreach ([
            'positions.view', 'positions.create', 'positions.delete',
            'hr_policies.view', 'hr_policies.acknowledge', 'hr_policies.archive',
            'acknowledgements.view', 'acknowledgements.manage',
        ] as $invented) {
            $this->assertNotContains(
                $invented,
                $declared,
                "[{$invented}] was never decided in Phase 0 and must not appear."
            );
        }

        $this->assertContains('positions.manage', $declared);
        $this->assertContains('hr_policies.manage', $declared);
        $this->assertContains('hr_policies.publish', $declared);
    }

    // --- Reading and recording -------------------------------------------------------

    #[Test]
    public function both_roles_may_read_the_chart_and_the_library(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $actor = $this->{$role}();

            $this->assertTrue($actor->can('view', Position::factory()->create()));
            $this->assertTrue($actor->can('view', HrPolicy::factory()->create()));
            $this->assertTrue($actor->can('view', HrPolicyAcknowledgement::factory()->create()));
            $this->assertTrue($actor->can('create', HrPolicyAcknowledgement::class));
        }
    }

    // --- Nothing in Phase 7 is deletable or rewritable ----------------------------------

    #[Test]
    public function no_role_can_delete_any_business_system_record(): void
    {
        $records = [
            Position::factory()->create(),
            HrPolicy::factory()->create(),
            HrPolicyAcknowledgement::factory()->create(),
        ];

        foreach ($records as $record) {
            foreach (['superAdmin', 'admin', 'staff'] as $role) {
                $this->assertFalse(
                    $this->{$role}()->can('delete', $record),
                    sprintf('[%s] must not delete a %s.', $role, $record::class),
                );
            }
        }
    }

    #[Test]
    public function no_role_can_update_a_sign_off(): void
    {
        $acknowledgement = HrPolicyAcknowledgement::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('update', $acknowledgement));
        }
    }

    #[Test]
    public function a_super_admin_cannot_delete_a_business_system_record_either(): void
    {
        $this->actingAs($this->superAdmin());

        $this->expectException(\RuntimeException::class);
        Position::factory()->create()->delete();
    }

    #[Test]
    public function no_business_system_policy_names_a_role(): void
    {
        foreach (['PositionPolicy', 'HrPolicyPolicy', 'HrPolicyAcknowledgementPolicy'] as $policy) {
            $source = file_get_contents(base_path("app/Policies/{$policy}.php"));

            foreach (['hasRole', 'super-admin', 'super_admin', "'admin'", "'staff'"] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$policy} must ask about capabilities, not roles."
                );
            }
        }
    }

    #[Test]
    public function the_three_roles_are_unchanged(): void
    {
        $roles = Role::query()->pluck('name')->sort()->values()->all();

        $this->assertSame(['admin', 'staff', 'super-admin'], $roles);
    }
}
