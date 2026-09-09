<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Domain\Attachments\DocumentService;
use App\Domain\Attachments\NoteService;
use App\Domain\Trackers\ActionItemService;
use App\Domain\Trackers\DayPlanService;
use App\Livewire\Customers\ActionPlan;
use App\Livewire\Customers\DayPlan;
use App\Livewire\Customers\Documents;
use App\Livewire\Customers\Enrollments;
use App\Livewire\Customers\Notes;
use App\Livewire\Customers\Reports as CustomerReports;
use App\Livewire\Customers\Show;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\ReportArtifact;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "One participant can never see another participant's data. This is built in,
 * not a setting."
 *
 * Every test here does the same thing: takes a legitimate screen for business
 * A and feeds it a record id belonging to business B, the way a tampered
 * request would. None of them relies on a filtered UI - the payload is sent
 * straight to the endpoint.
 */
class CustomerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $mine;

    private Customer $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        $this->mine = Customer::factory()->create(['name' => 'Alpha Metalworks']);
        $this->theirs = Customer::factory()->create(['name' => 'Beta Textiles']);
    }

    #[Test]
    public function a_contact_belonging_to_another_business_cannot_be_touched(): void
    {
        $theirContact = CustomerContact::factory()->create(['customer_id' => $this->theirs->getKey()]);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['customer' => $this->mine])
            ->call('makePrimary', $theirContact->getKey())
            ->assertNotFound();

        $this->assertFalse((bool) $theirContact->fresh()->is_primary);
    }

    #[Test]
    public function an_enrolment_belonging_to_another_business_cannot_be_withdrawn(): void
    {
        $theirEnrolment = $this->enrolment($this->theirs);

        Livewire::actingAs($this->admin())
            ->test(Enrollments::class, ['customer' => $this->mine])
            ->call('startWithdrawal', $theirEnrolment->getKey())
            ->assertNotFound();

        $this->assertSame('enrolled', $theirEnrolment->fresh()->status);
    }

    #[Test]
    public function a_day_plan_item_belonging_to_another_business_cannot_be_completed(): void
    {
        $theirEnrolment = $this->enrolment($this->theirs);

        $item = app(DayPlanService::class)->create(
            enrollment: $theirEnrolment,
            planDate: now()->toDateString(),
            task: 'Their task',
            actor: $this->admin(),
        );

        Livewire::actingAs($this->admin())
            ->test(DayPlan::class, ['customer' => $this->mine])
            ->call('complete', $item->getKey())
            ->assertNotFound();

        $this->assertSame('planned', $item->fresh()->status);
    }

    #[Test]
    public function an_action_item_belonging_to_another_business_cannot_be_moved(): void
    {
        $theirEnrolment = $this->enrolment($this->theirs);

        $item = app(ActionItemService::class)->create(
            enrollment: $theirEnrolment,
            title: 'Their action',
            actor: $this->admin(),
        );

        Livewire::actingAs($this->admin())
            ->test(ActionPlan::class, ['customer' => $this->mine])
            ->call('moveTo', $item->getKey(), 'done')
            ->assertNotFound();

        $this->assertSame('open', $item->fresh()->status);
    }

    #[Test]
    public function a_note_on_another_business_cannot_be_read_or_changed(): void
    {
        $theirNote = app(NoteService::class)
            ->create($this->theirs, 'Confidential to Beta.', $this->admin(), true);

        Livewire::actingAs($this->admin())
            ->test(Notes::class, ['customer' => $this->mine])
            ->assertDontSee('Confidential to Beta.')
            ->call('archive', $theirNote->getKey())
            ->assertNotFound();

        $this->assertNull($theirNote->fresh()->archived_at);
    }

    #[Test]
    public function a_document_on_another_business_cannot_be_downloaded(): void
    {
        $theirDocument = app(DocumentService::class)->attachByStaff(
            $this->theirs,
            [
                'disk' => 'local',
                'path' => 'documents/theirs.txt',
                'original_name' => 'theirs.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 10,
            ],
            $this->admin(),
        );

        Livewire::actingAs($this->admin())
            ->test(Documents::class, ['customer' => $this->mine])
            ->call('download', $theirDocument->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function another_businesss_report_cannot_be_downloaded_from_this_workspace(): void
    {
        $theirEnrolment = $this->enrolment($this->theirs);

        $artifact = ReportArtifact::factory()->create([
            'subject_type' => (new Enrollment)->getMorphClass(),
            'subject_id' => $theirEnrolment->getKey(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(CustomerReports::class, ['customer' => $this->mine])
            ->call('download', $artifact->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function a_workspace_lists_only_its_own_records(): void
    {
        $this->enrolment($this->mine);
        $this->enrolment($this->theirs);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['customer' => $this->mine])
            ->assertSee('Alpha Metalworks')
            ->assertDontSee('Beta Textiles');
    }

    #[Test]
    public function the_customer_id_is_locked_against_client_tampering(): void
    {
        // #[Locked] means a payload that rewrites the workspace id is refused
        // by Livewire rather than honoured. Without it, every screen in the
        // workspace would take its scope from the browser.
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['customer' => $this->mine])
            ->assertSet('customerId', (int) $this->mine->getKey());

        $this->expectException(
            CannotUpdateLockedPropertyException::class
        );

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['customer' => $this->mine])
            ->set('customerId', (int) $this->theirs->getKey());
    }

    #[Test]
    public function a_customer_cannot_be_deleted_through_the_ui(): void
    {
        // There is no customers.delete permission, no delete route, and
        // CustomerPolicy::delete returns false. Removal is archival (ADR-011).
        $superAdmin = $this->superAdmin();

        // `delete` is in authorization.guarded_abilities, so Gate::before
        // falls through and CustomerPolicy's refusal stands even here.
        $this->assertFalse($superAdmin->can('delete', $this->mine));

        // No soft-delete mechanism exists on any model, so there is no
        // restore/forceDelete operation for a UI to expose in the first place.
        $this->assertNotContains(
            SoftDeletes::class,
            class_uses_recursive(Customer::class),
        );

        $permissions = collect(config('authorization.permissions'))->flatten()->all();
        $this->assertNotContains('customers.delete', $permissions);

        // No route in this application destroys anything. The catch-all
        // redirect at / answers every verb and is excluded: it belongs to the
        // framework and points at nothing of ours.
        $destructive = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true))
            ->reject(fn ($route): bool => $route->getName() === 'home')
            ->map(fn ($route): string => (string) $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $destructive, 'The internal application exposes no destructive route.');

        // Archiving is what is offered instead.
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['customer' => $this->mine])
            ->call('archiveCustomer');

        $this->assertSame('archived', $this->mine->fresh()->status);
        $this->assertDatabaseHas('customers', ['id' => $this->mine->getKey()]);
    }

    #[Test]
    public function no_route_or_model_creates_a_customer_login(): void
    {
        // Customer != User is structural. Nothing in the UI layer may soften
        // it, so this asserts the absence rather than trusting convention.
        $this->assertFalse(Route::has('register'));

        foreach (['customer.login', 'customers.login', 'customer.register'] as $name) {
            $this->assertFalse(Route::has($name));
        }

        $this->assertNotInstanceOf(
            Authenticatable::class,
            new Customer,
        );

        foreach (['password', 'remember_token', 'email_verified_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('customers', $column));
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
