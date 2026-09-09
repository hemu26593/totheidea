<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Livewire\Customers\ActionPlan;
use App\Livewire\Customers\Assignments;
use App\Livewire\Customers\DayPlan;
use App\Livewire\Customers\Documents;
use App\Livewire\Customers\Enrollments as EnrollmentsScreen;
use App\Livewire\Customers\Forms as FormsScreen;
use App\Livewire\Customers\FundPlan as FundPlanScreen;
use App\Livewire\Customers\Mmd;
use App\Livewire\Customers\Notes as NotesScreen;
use App\Livewire\Customers\Reports as ReportsScreen;
use App\Livewire\Customers\TimeGrid;
use App\Models\ActionItem;
use App\Models\AssignmentInstance;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\MmdEntry;
use App\Models\Note;
use App\Models\Program;
use App\Models\SessionInstance;
use App\Models\TimeGridEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT section 21: the customer boundary, exercised rather than assumed.
 *
 * Internal staff are not partitioned by customer - a consultant with
 * customers.view legitimately opens any client's workspace. The boundary this
 * file exercises is a different one, and the one that actually matters: a
 * record belonging to Beta must never be reachable THROUGH Alpha's workspace,
 * however the id arrives. Every refusal below is executed, never inferred from
 * a hidden button.
 */
class CrossCustomerIsolationUatTest extends TestCase
{
    use RefreshDatabase;

    private Customer $alpha;

    private Customer $beta;

    private Enrollment $alphaEnrollment;

    private Enrollment $betaEnrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        $program = Program::factory()->create(['session_count' => 6]);

        $this->alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $this->beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        $this->alphaEnrollment = Enrollment::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'batch_id' => Batch::factory()->create(['program_id' => $program->getKey()])->getKey(),
        ]);

        $this->betaEnrollment = Enrollment::factory()->create([
            'customer_id' => $this->beta->getKey(),
            'batch_id' => Batch::factory()->create(['program_id' => $program->getKey()])->getKey(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The workspace anchor itself
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_workspace_customer_id_cannot_be_swapped_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())
            ->test(DayPlan::class, ['customer' => $this->alpha])
            ->set('customerId', $this->beta->getKey());
    }

    #[Test]
    public function each_workspace_screen_renders_only_its_own_customers_records(): void
    {
        $admin = $this->admin();

        Note::factory()->create([
            'notable_type' => Customer::class,
            'notable_id' => $this->alpha->getKey(),
            'body' => 'Alpha shop-floor observation',
            'author_id' => $admin->getKey(),
        ]);

        Note::factory()->create([
            'notable_type' => Customer::class,
            'notable_id' => $this->beta->getKey(),
            'body' => 'Beta weaving observation',
            'author_id' => $admin->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(NotesScreen::class, ['customer' => $this->alpha])
            ->assertSee('Alpha shop-floor observation')
            ->assertDontSee('Beta weaving observation');

        Livewire::actingAs($admin)
            ->test(NotesScreen::class, ['customer' => $this->beta])
            ->assertSee('Beta weaving observation')
            ->assertDontSee('Alpha shop-floor observation');
    }

    /*
    |--------------------------------------------------------------------------
    | An id from the other customer, injected into every screen that takes one
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function betas_enrollment_cannot_be_completed_through_alphas_enrolments_screen(): void
    {
        Livewire::actingAs($this->admin())
            ->test(EnrollmentsScreen::class, ['customer' => $this->alpha])
            ->call('complete', $this->betaEnrollment->getKey())
            ->assertNotFound();

        $this->assertNull($this->betaEnrollment->fresh()->completed_at);
    }

    #[Test]
    public function betas_enrollment_cannot_receive_a_form_started_from_alphas_forms_screen(): void
    {
        $template = FormTemplate::factory()->create(['customer_id' => null]);

        Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->betaEnrollment->getKey())
            ->set('templateId', $template->getKey())
            ->call('start')
            ->assertNotFound();

        $this->assertDatabaseCount('form_submissions', 0);
    }

    #[Test]
    public function betas_private_form_template_cannot_be_used_from_alphas_forms_screen(): void
    {
        $betaTemplate = FormTemplate::factory()->create(['customer_id' => $this->beta->getKey()]);

        Livewire::actingAs($this->admin())
            ->test(FormsScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->alphaEnrollment->getKey())
            ->set('templateId', $betaTemplate->getKey())
            ->call('start')
            ->assertNotFound();

        $this->assertDatabaseCount('form_submissions', 0);
    }

    #[Test]
    public function betas_assignment_instance_cannot_be_opened_from_alphas_assignments_screen(): void
    {
        $instance = AssignmentInstance::factory()->create([
            'session_instance_id' => SessionInstance::factory()->create([
                'batch_id' => $this->betaEnrollment->batch_id,
            ])->getKey(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(Assignments::class, ['customer' => $this->alpha])
            ->call('startSubmission', $instance->getKey(), $this->betaEnrollment->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function betas_day_plan_item_cannot_be_completed_from_alphas_day_plan(): void
    {
        $item = DayPlanItem::factory()->create([
            'customer_id' => $this->beta->getKey(),
            'enrollment_id' => $this->betaEnrollment->getKey(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(DayPlan::class, ['customer' => $this->alpha])
            ->call('complete', $item->getKey())
            ->assertNotFound();

        $this->assertSame(DayPlanItem::STATUS_PLANNED, $item->fresh()->status);
    }

    #[Test]
    public function betas_time_grid_entry_cannot_be_edited_from_alphas_time_grid(): void
    {
        $entry = TimeGridEntry::factory()->create([
            'enrollment_id' => $this->betaEnrollment->getKey(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(TimeGrid::class, ['customer' => $this->alpha])
            ->call('startEditing', $entry->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function betas_action_item_cannot_be_moved_from_alphas_action_plan(): void
    {
        $item = ActionItem::factory()->create([
            'enrollment_id' => $this->betaEnrollment->getKey(),
            'status' => ActionItem::STATUS_OPEN,
        ]);

        Livewire::actingAs($this->admin())
            ->test(ActionPlan::class, ['customer' => $this->alpha])
            ->call('moveTo', $item->getKey(), ActionItem::STATUS_IN_PROGRESS)
            ->assertNotFound();

        $this->assertSame(ActionItem::STATUS_OPEN, $item->fresh()->status);
    }

    #[Test]
    public function betas_document_cannot_be_downloaded_or_archived_from_alphas_documents(): void
    {
        $document = Document::factory()->create([
            'documentable_type' => Customer::class,
            'documentable_id' => $this->beta->getKey(),
        ]);

        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(Documents::class, ['customer' => $this->alpha])
            ->call('download', $document->getKey())
            ->assertNotFound();

        Livewire::actingAs($admin)
            ->test(Documents::class, ['customer' => $this->alpha])
            ->call('archive', $document->getKey())
            ->assertNotFound();

        $this->assertNull($document->fresh()->archived_at);
    }

    #[Test]
    public function betas_note_cannot_be_edited_or_archived_from_alphas_notes(): void
    {
        $admin = $this->admin();

        $note = Note::factory()->create([
            'notable_type' => Customer::class,
            'notable_id' => $this->beta->getKey(),
            'author_id' => $admin->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(NotesScreen::class, ['customer' => $this->alpha])
            ->call('startEditing', $note->getKey())
            ->assertNotFound();

        Livewire::actingAs($admin)
            ->test(NotesScreen::class, ['customer' => $this->alpha])
            ->call('archive', $note->getKey())
            ->assertNotFound();

        $this->assertNull($note->fresh()->archived_at);
    }

    #[Test]
    public function betas_enrollment_cannot_be_the_subject_of_a_report_exported_from_alpha(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ReportsScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->betaEnrollment->getKey())
            ->call('export', 'csv')
            ->assertNotFound();

        $this->assertDatabaseCount('report_artifacts', 0);
    }

    #[Test]
    public function betas_enrollment_cannot_be_attributed_an_mmd_entry_recorded_under_alpha(): void
    {
        $screen = Livewire::actingAs($this->admin())
            ->test(Mmd::class, ['customer' => $this->alpha]);

        // The MMD screen attributes entries to the workspace's own enrolment; it
        // never accepts one from the request. Record under Alpha and confirm the
        // row lands on Alpha and is invisible from Beta.
        $screen->call('startRecording')
            ->set('date', now()->toDateString())
            ->set('figures.fund_in', 1000)
            ->call('record')
            ->assertHasNoErrors();

        $this->assertSame(
            [$this->alpha->getKey()],
            MmdEntry::query()->pluck('customer_id')->unique()->values()->all(),
        );

        Livewire::actingAs($this->admin())
            ->test(Mmd::class, ['customer' => $this->beta])
            ->assertDontSee('1,000');
    }

    #[Test]
    public function betas_fund_plan_is_not_reachable_by_naming_its_enrollment_under_alpha(): void
    {
        // The refusal lands on the very first render that carries the foreign id:
        // the screen resolves its enrolment through the workspace before it
        // renders anything, so there is no state in which a line could be added.
        Livewire::actingAs($this->admin())
            ->test(FundPlanScreen::class, ['customer' => $this->alpha])
            ->set('enrollmentId', $this->betaEnrollment->getKey())
            ->assertNotFound();

        $this->assertDatabaseCount('fund_plans', 0);
    }
}
