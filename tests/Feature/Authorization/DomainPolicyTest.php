<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\AccessGrant;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\TermsAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1 policies against the Phase 0 permission matrix.
 *
 * The deletion assertions are the load-bearing ones: 'delete' is a guarded
 * ability, so Gate::before falls through to the policy even for a Super Admin.
 * That is what makes "customers are archived, never deleted" absolute.
 */
class DomainPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    // --- Deletion is refused for everyone ---------------------------------

    #[Test]
    public function no_role_can_delete_a_customer(): void
    {
        $customer = Customer::factory()->create();

        foreach (['superAdmin', 'admin', 'staff'] as $role) {
            $this->assertFalse(
                $this->{$role}()->can('delete', $customer),
                "[{$role}] must not be able to delete a customer."
            );
        }
    }

    #[Test]
    public function not_even_a_super_admin_can_delete_a_customer(): void
    {
        // Gate::before grants a Super Admin everything EXCEPT the guarded
        // abilities. 'delete' is guarded, so CustomerPolicy::delete() is
        // consulted and refuses.
        $this->assertContains('delete', config('authorization.guarded_abilities'));
        $this->assertFalse($this->superAdmin()->can('delete', Customer::factory()->create()));
    }

    #[Test]
    public function batches_enrolments_grants_and_acceptances_are_never_deletable(): void
    {
        $subjects = [
            Batch::factory()->create(),
            Enrollment::factory()->create(),
            AccessGrant::factory()->create(),
            TermsAcceptance::factory()->create(),
            CustomerContact::factory()->create(),
        ];

        foreach ($subjects as $subject) {
            foreach (['superAdmin', 'admin', 'staff'] as $role) {
                $this->assertFalse(
                    $this->{$role}()->can('delete', $subject),
                    class_basename($subject).' must not be deletable by '.$role
                );
            }
        }
    }

    // --- Customers --------------------------------------------------------

    #[Test]
    public function admin_may_create_edit_and_archive_a_customer(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $this->assertTrue($admin->can('create', Customer::class));
        $this->assertTrue($admin->can('update', $customer));
        $this->assertTrue($admin->can('archive', $customer));
    }

    #[Test]
    public function staff_may_view_and_edit_but_not_create_or_archive_a_customer(): void
    {
        $staff = $this->staff();
        $customer = Customer::factory()->create();

        $this->assertTrue($staff->can('view', $customer));
        $this->assertTrue($staff->can('update', $customer));
        $this->assertFalse($staff->can('create', Customer::class));
        $this->assertFalse($staff->can('archive', $customer));
    }

    // --- Access grants ----------------------------------------------------

    #[Test]
    public function issuing_and_revoking_a_grant_require_their_own_permissions(): void
    {
        $grant = AccessGrant::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $actor = $this->{$role}();
            $this->assertTrue($actor->can('create', AccessGrant::class), "{$role} may issue");
            $this->assertTrue($actor->can('revoke', $grant), "{$role} may revoke");
        }
    }

    #[Test]
    public function a_user_without_the_grant_permissions_cannot_issue_or_revoke(): void
    {
        // A user with no role holds nothing: roles are explicit allowlists.
        $nobody = User::factory()->create();
        $grant = AccessGrant::factory()->create();

        $this->assertFalse($nobody->can('create', AccessGrant::class));
        $this->assertFalse($nobody->can('revoke', $grant));
    }

    // --- Batches and enrolments -------------------------------------------

    #[Test]
    public function staff_may_view_batches_but_not_administer_them(): void
    {
        $staff = $this->staff();
        $batch = Batch::factory()->create();

        $this->assertTrue($staff->can('view', $batch));
        $this->assertFalse($staff->can('create', Batch::class));
        $this->assertFalse($staff->can('update', $batch));
        $this->assertFalse($staff->can('archive', $batch));
    }

    #[Test]
    public function admin_may_administer_batches_and_enrolments(): void
    {
        $admin = $this->admin();
        $batch = Batch::factory()->create();
        $enrollment = Enrollment::factory()->create();

        $this->assertTrue($admin->can('create', Batch::class));
        $this->assertTrue($admin->can('update', $batch));
        $this->assertTrue($admin->can('create', Enrollment::class));
        $this->assertTrue($admin->can('update', $enrollment));
    }

    #[Test]
    public function staff_cannot_create_an_enrolment(): void
    {
        // Enrolment administration follows batches.edit, which Staff lacks.
        $this->assertFalse($this->staff()->can('create', Enrollment::class));
    }

    // --- Terms acceptances ------------------------------------------------

    #[Test]
    public function the_policy_refuses_to_update_a_terms_acceptance(): void
    {
        $acceptance = TermsAcceptance::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('update', $acceptance));
        }
    }

    #[Test]
    public function immutability_of_an_acceptance_does_not_depend_on_the_policy(): void
    {
        // 'update' is NOT a guarded ability, so Gate::before short-circuits
        // the policy for a Super Admin. That is correct - guarding 'update'
        // globally would strip Super Admin of every ordinary edit.
        //
        // Immutability is therefore guaranteed where it cannot be bypassed:
        // on the model itself, exactly as audit_logs does. This test exists
        // to record that the model, not the policy, is the real guarantee.
        $acceptance = TermsAcceptance::factory()->create();

        $this->assertTrue($this->superAdmin()->can('update', $acceptance));

        $this->expectException(\RuntimeException::class);

        $acceptance->update(['accepted_name' => 'Someone Else']);
    }

    // --- Regression -------------------------------------------------------

    #[Test]
    public function the_super_admin_guarded_ability_mechanism_is_intact(): void
    {
        $superAdmin = $this->superAdmin();

        // Ordinary abilities still granted through Gate::before.
        $this->assertTrue($superAdmin->can('settings.manage'));
        $this->assertTrue($superAdmin->can('customers.archive'));
        $this->assertTrue($superAdmin->can('access_grants.issue'));

        // Guarded ones still fall through to a policy.
        $this->assertFalse($superAdmin->can('delete', $superAdmin));
        $this->assertFalse($superAdmin->can('approve'));

        $this->assertSame(
            ['delete', 'deactivate', 'assignRole', 'approve'],
            config('authorization.guarded_abilities'),
        );
    }
}
