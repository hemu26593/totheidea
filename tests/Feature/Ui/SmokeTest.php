<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\AiGeneration;
use App\Models\AssignmentInstance;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\Program;
use App\Models\SessionInstance;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every internal screen renders.
 *
 * A blank list is not a defect - the point is that no route 500s and no
 * navigation link is dead. Behaviour and authorization are asserted in the
 * dedicated files alongside this one.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function every_screen_renders_for_a_super_admin_with_no_data(): void
    {
        $actor = $this->superAdmin();
        $customer = Customer::factory()->create();

        foreach ($this->customerRoutes() as $name) {
            $this->actingAs($actor)
                ->get(route($name, $customer))
                ->assertOk();
        }

        foreach ($this->globalRoutes() as $name) {
            $this->actingAs($actor)->get(route($name))->assertOk();
        }
    }

    #[Test]
    public function every_screen_renders_with_data_present(): void
    {
        $actor = $this->superAdmin();

        $customer = Customer::factory()->create();
        $program = Program::factory()->create();
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        AiGeneration::factory()->forCustomer($customer)->create();

        foreach ($this->customerRoutes() as $name) {
            $this->actingAs($actor)->get(route($name, $customer))->assertOk();
        }

        foreach ($this->globalRoutes() as $name) {
            $this->actingAs($actor)->get(route($name))->assertOk();
        }

        $this->actingAs($actor)->get(route('batches.show', $batch))->assertOk();
    }

    #[Test]
    public function detail_screens_render(): void
    {
        $actor = $this->superAdmin();

        $session = SessionInstance::factory()->create();
        $assignment = AssignmentInstance::factory()->create();
        $submission = FormSubmission::factory()->create();
        $generation = AiGeneration::factory()->create();

        $this->actingAs($actor)->get(route('sessions.show', $session))->assertOk();
        $this->actingAs($actor)->get(route('assignments.show', $assignment))->assertOk();
        $this->actingAs($actor)->get(route('forms.show', $submission))->assertOk();
        $this->actingAs($actor)->get(route('ai.generations.show', $generation))->assertOk();
    }

    #[Test]
    public function staff_can_open_every_screen_their_permissions_allow(): void
    {
        // The nav offers a Staff member only what they may open. This asserts
        // there is no route in that list they are then refused - a dead link
        // in the sidebar is a bug even though it is not a security hole.
        $staff = $this->staff();
        $customer = Customer::factory()->create();

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                $this->actingAs($staff)
                    ->get($item['url'])
                    ->assertOk();
            }
        }

        $this->actingAs($staff)->get(route('customers.show', $customer))->assertOk();
    }

    /**
     * @return array<int, string>
     */
    private function customerRoutes(): array
    {
        return [
            'customers.show', 'customers.edit', 'customers.enrollments', 'customers.forms',
            'customers.sessions', 'customers.assignments', 'customers.attendance',
            'customers.day-plan', 'customers.time-grid', 'customers.mmd', 'customers.fund-plan',
            'customers.action-plan', 'customers.business', 'customers.documents',
            'customers.notes', 'customers.reports', 'customers.ai',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function globalRoutes(): array
    {
        return [
            'dashboard', 'customers.index', 'customers.create', 'batches.index',
            'sessions.index', 'assignments.index', 'attendance.index', 'reports.index',
            'notifications.index', 'ai.generations', 'ai.prompts', 'users.index',
            'admin.programs', 'admin.curriculum', 'admin.form-templates', 'admin.skill-areas',
            'admin.roles',
        ];
    }
}
