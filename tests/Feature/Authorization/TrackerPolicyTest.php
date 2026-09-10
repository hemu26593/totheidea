<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\ActionItem;
use App\Models\DayPlanItem;
use App\Models\FundPlan;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\TimeGridEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The tracker policies against the Phase 0 permission matrix.
 *
 * Two lines drawn in Phase 0 are re-asserted here because Phase 6 is the first
 * phase where they have anything to guard:
 *
 *   - Staff may record and amend MMD figures, but may NOT set the targets
 *     those figures are measured against.
 *   - Staff may build a fund plan, but may NOT approve it.
 *
 * Both are approval-shaped acts. Neither is HR, which is Phase 7 and untouched
 * here.
 */
class TrackerPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    // --- The two Phase 0 lines -------------------------------------------------

    #[Test]
    public function staff_may_record_mmd_figures_but_not_set_targets(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->can('view', MmdEntry::factory()->create()));
        $this->assertTrue($staff->can('amend', MmdEntry::factory()->create()));

        $this->assertFalse($staff->can('mmd.set_targets'));
        $this->assertFalse($staff->can('create', MmdTarget::class));
        $this->assertFalse($staff->can('update', MmdTarget::factory()->create()));
    }

    #[Test]
    public function admin_may_set_targets(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->can('mmd.set_targets'));
        $this->assertTrue($admin->can('create', MmdTarget::class));
    }

    #[Test]
    public function staff_may_build_a_fund_plan_but_not_approve_it(): void
    {
        $staff = $this->staff();
        $plan = FundPlan::factory()->create();

        $this->assertTrue($staff->can('create', FundPlan::class));
        $this->assertTrue($staff->can('update', $plan));
        $this->assertFalse($staff->can('approve', $plan));
    }

    #[Test]
    public function admin_may_approve_a_fund_plan(): void
    {
        $this->assertTrue($this->admin()->can('approve', FundPlan::factory()->create()));
    }

    #[Test]
    public function the_hr_rule_is_untouched(): void
    {
        // HR is Phase 7. Phase 6 must not have moved this line.
        $this->assertFalse($this->staff()->can('hr_policies.publish'));
        $this->assertTrue($this->admin()->can('hr_policies.publish'));
    }

    // --- The everyday tracker permissions -----------------------------------------

    #[Test]
    public function staff_operates_the_trackers_it_is_responsible_for(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->can('create', DayPlanItem::class));
        $this->assertTrue($staff->can('complete', DayPlanItem::factory()->create()));
        $this->assertTrue($staff->can('create', TimeGridEntry::class));
        $this->assertTrue($staff->can('create', MmdEntry::class));
        $this->assertTrue($staff->can('create', ActionItem::class));
        $this->assertTrue($staff->can('complete', ActionItem::factory()->create()));
    }

    #[Test]
    public function admin_holds_every_tracker_permission(): void
    {
        $admin = $this->admin();

        foreach ([
            'day_plans.view', 'day_plans.manage', 'time_grid.manage',
            'mmd.view', 'mmd.manage', 'mmd.set_targets',
            'fund_plans.view', 'fund_plans.manage', 'fund_plans.approve',
            'action_items.view', 'action_items.manage',
        ] as $permission) {
            $this->assertTrue($admin->can($permission), "Admin should hold [{$permission}].");
        }
    }

    // --- Nothing in Phase 6 is deletable ---------------------------------------------

    #[Test]
    public function no_role_can_delete_any_tracker_record(): void
    {
        $records = [
            DayPlanItem::factory()->create(),
            TimeGridEntry::factory()->create(),
            MmdEntry::factory()->create(),
            MmdTarget::factory()->create(),
            FundPlan::factory()->create(),
            ActionItem::factory()->create(),
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
    public function a_super_admin_cannot_delete_a_tracker_record_either(): void
    {
        // 'delete' is a guarded ability, so Gate::before defers to the policy;
        // and the absolute refusal lives on the models, which throw.
        $this->assertContains('delete', config('authorization.guarded_abilities'));
        $this->actingAs($this->superAdmin());

        $this->expectException(\RuntimeException::class);
        MmdEntry::factory()->create()->delete();
    }

    #[Test]
    public function no_tracker_policy_names_a_role(): void
    {
        foreach ([
            'DayPlanItemPolicy', 'TimeGridEntryPolicy', 'MmdEntryPolicy',
            'MmdTargetPolicy', 'FundPlanPolicy', 'ActionItemPolicy',
        ] as $policy) {
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
