<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Domain\Business\HrPolicyService;
use App\Domain\Trackers\FundPlanService;
use App\Livewire\Customers\BusinessSystems;
use App\Livewire\Customers\FundPlan as FundPlanScreen;
use App\Livewire\Customers\Mmd;
use App\Livewire\Customers\Reports;
use App\Livewire\Notifications\Index as NotificationsScreen;
use App\Livewire\Users\Index;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FundPlan;
use App\Models\HrPolicy;
use App\Models\NotificationDispatch;
use App\Models\Program;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What each role may and may not do THROUGH THE UI.
 *
 * A Livewire component's public methods are HTTP endpoints, so these tests
 * call them directly rather than clicking buttons: a control the template
 * hides is not a control the server refuses, and only the second is
 * authorization.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function an_unauthenticated_visitor_reaches_no_bmp_screen(): void
    {
        $customer = Customer::factory()->create();

        foreach ([
            route('dashboard'),
            route('customers.index'),
            route('customers.show', $customer),
            route('batches.index'),
            route('sessions.index'),
            route('assignments.index'),
            route('reports.index'),
            route('ai.generations'),
            route('admin.roles'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function an_inactive_user_is_turned_away(): void
    {
        $user = $this->admin(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect();
    }

    #[Test]
    public function staff_cannot_reach_admin_only_screens(): void
    {
        $staff = $this->staff();

        // roles.manage is Super Admin only - Admin does not hold it either,
        // because the audit log records Admin's own actions.
        $this->actingAs($staff)->get(route('admin.roles'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.roles'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('admin.roles'))->assertOk();
    }

    #[Test]
    public function staff_cannot_set_mmd_targets(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = $this->enrolment($customer);

        Livewire::actingAs($this->staff())
            ->test(Mmd::class, ['customer' => $customer])
            ->set('targetEnrollmentId', $enrollment->getKey())
            ->set('targetMetric', 'fund_in')
            ->set('targetPeriodStart', now()->startOfMonth()->toDateString())
            ->set('targetPeriodEnd', now()->endOfMonth()->toDateString())
            ->set('targetValue', 1000)
            ->call('saveTarget')
            ->assertForbidden();

        $this->assertDatabaseCount('mmd_targets', 0);

        // And the control is not offered either.
        Livewire::actingAs($this->staff())
            ->test(Mmd::class, ['customer' => $customer])
            ->assertSet('canSetTargets', false);
    }

    #[Test]
    public function an_admin_can_set_mmd_targets(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = $this->enrolment($customer);

        Livewire::actingAs($this->admin())
            ->test(Mmd::class, ['customer' => $customer])
            ->set('targetEnrollmentId', $enrollment->getKey())
            ->set('targetMetric', 'fund_in')
            ->set('targetPeriodStart', now()->startOfMonth()->toDateString())
            ->set('targetPeriodEnd', now()->endOfMonth()->toDateString())
            ->set('targetValue', 1000)
            ->call('saveTarget');

        $this->assertDatabaseCount('mmd_targets', 1);
    }

    #[Test]
    public function staff_cannot_approve_a_fund_plan(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = $this->enrolment($customer);

        $plan = app(FundPlanService::class)
            ->createMonth($enrollment, (int) now()->year, (int) now()->month, null, $this->admin());

        Livewire::actingAs($this->staff())
            ->test(FundPlanScreen::class, ['customer' => $customer])
            ->set('enrollmentId', $enrollment->getKey())
            ->set('year', (int) now()->year)
            ->set('month', (int) now()->month)
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(FundPlan::STATUS_DRAFT, $plan->fresh()->status);
    }

    #[Test]
    public function staff_cannot_publish_an_hr_policy(): void
    {
        $customer = Customer::factory()->create();

        $policy = app(HrPolicyService::class)
            ->draft($customer, 'Leave policy', 'Body', 'v1', $this->staff());

        Livewire::actingAs($this->staff())
            ->test(BusinessSystems::class, ['customer' => $customer])
            ->call('publishPolicy', $policy->getKey())
            ->assertForbidden();

        $this->assertSame(HrPolicy::STATUS_DRAFT, $policy->fresh()->status);

        // An Admin may.
        Livewire::actingAs($this->admin())
            ->test(BusinessSystems::class, ['customer' => $customer])
            ->call('publishPolicy', $policy->getKey());

        $this->assertSame(HrPolicy::STATUS_PUBLISHED, $policy->fresh()->status);
    }

    #[Test]
    public function staff_cannot_manage_notifications(): void
    {
        $dispatch = NotificationDispatch::factory()->create([
            'status' => NotificationDispatch::STATUS_FAILED,
        ]);

        Livewire::actingAs($this->staff())
            ->test(NotificationsScreen::class)
            ->call('retry', $dispatch->getKey())
            ->assertForbidden();

        $this->assertSame(NotificationDispatch::STATUS_FAILED, $dispatch->fresh()->status);

        // Reading the screen is fine: Staff hold notifications.view.
        $this->actingAs($this->staff())->get(route('notifications.index'))->assertOk();
    }

    #[Test]
    public function staff_cannot_export_a_report(): void
    {
        // Export writes a file that leaves the system - a data-exfiltration
        // boundary. Staff hold reports.view and not reports.export.
        $customer = Customer::factory()->create();
        $this->enrolment($customer);

        Livewire::actingAs($this->staff())
            ->test(Reports::class, ['customer' => $customer])
            ->call('export', 'pdf')
            ->assertForbidden();

        $this->assertDatabaseCount('report_artifacts', 0);
    }

    #[Test]
    public function an_admin_cannot_modify_a_super_admin(): void
    {
        $admin = $this->admin();
        $superAdmin = $this->superAdmin();

        // ADR-012: you may only act on users you rank at or above.
        $this->assertFalse($admin->can('update', $superAdmin));
        $this->assertFalse($admin->can('deactivate', $superAdmin));
        $this->assertFalse($admin->can('assignRole', $superAdmin));

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('deactivate', $superAdmin->getKey())
            ->assertForbidden();

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    #[Test]
    public function staff_cannot_assign_roles(): void
    {
        $staff = $this->staff();
        $target = User::factory()->create();

        $this->assertFalse($staff->can('users.assign_role'));
        $this->assertFalse($staff->can('assignRole', $target));

        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
    }

    #[Test]
    public function the_sidebar_offers_staff_nothing_they_cannot_open(): void
    {
        // A hidden link is not authorization - but a visible link an actor is
        // refused is a bug worth failing on.
        $this->actingAs($this->staff());

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                $this->get($item['url'])->assertOk();
            }
        }
    }

    private function enrolment(Customer $customer): Enrollment
    {
        $program = Program::factory()->create();
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);

        return Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);
    }
}
