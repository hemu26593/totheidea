<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\NotificationDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Authorization for the dispatch log.
 *
 * Staff may read what has been sent; changing what a customer receives is not
 * Staff's to change, which is the same line drawn in the Phase 0 matrix.
 */
class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function staff_may_read_the_log_but_not_manage_notifications(): void
    {
        $staff = $this->staff();
        $dispatch = NotificationDispatch::factory()->create();

        $this->assertTrue($staff->can('view', $dispatch));
        $this->assertFalse($staff->can('manage', $dispatch));
    }

    #[Test]
    public function admin_may_manage_notifications(): void
    {
        $this->assertTrue($this->admin()->can('manage', NotificationDispatch::factory()->create()));
    }

    #[Test]
    public function nobody_deletes_a_dispatch(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        foreach (['superAdmin', 'admin', 'staff'] as $role) {
            $this->assertFalse(
                $this->{$role}()->can('delete', $dispatch),
                "[{$role}] must not delete a dispatch."
            );
        }
    }

    #[Test]
    public function dispatches_are_written_by_the_scheduler_not_by_people(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('create', NotificationDispatch::class));
            $this->assertFalse($this->{$role}()->can('update', $dispatch));
        }
    }

    #[Test]
    public function the_notification_policy_names_no_role(): void
    {
        $source = file_get_contents(base_path('app/Policies/NotificationDispatchPolicy.php'));

        foreach (['hasRole', 'super-admin', 'super_admin', "'admin'", "'staff'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function no_customer_role_was_introduced_by_external_access(): void
    {
        $roles = Role::query()->pluck('name')->sort()->values()->all();

        // Three roles, unchanged. No Customer role, no Consultant role - the
        // external path is a capability, and a capability is not a role.
        $this->assertSame(['admin', 'staff', 'super-admin'], $roles);
    }
}
