<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Enums\QuestionType;
use App\Livewire\Admin\Curriculum;
use App\Livewire\Admin\FormTemplates;
use App\Livewire\Customers\Assignments as CustomerAssignments;
use App\Livewire\Forms\FormRenderer;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Program;
use App\Models\SessionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressions for bugs found by driving the application in a real browser.
 *
 * Every test here corresponds to something that was actually wrong on screen
 * or actually reachable by a tampered request - not to a hypothetical.
 */
class HardeningRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    /*
    |--------------------------------------------------------------------------
    | A recorded answer must render as what was recorded
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_recorded_yes_renders_as_yes_and_not_as_an_empty_dash(): void
    {
        // The bug: loadExistingAnswers() picked the first non-null value
        // column, so a boolean true reached a <select> whose options are the
        // strings "1" and "0". PHP true never matches "1", the control fell
        // back to the empty option, and a consultant read "—" for a question
        // the participant had answered Yes.
        $actor = $this->admin();
        [$version, $submission] = $this->submissionWithAnswers($actor, true);

        $boolean = $version->questions()->where('type', QuestionType::Boolean->value)->firstOrFail();

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertSet('answers.'.$boolean->getKey(), '1');
    }

    #[Test]
    public function a_recorded_no_renders_as_no_rather_than_as_unanswered(): void
    {
        // The harder half: `false` and "not answered" are different facts, and
        // the control must be able to tell them apart.
        $actor = $this->admin();
        [$version, $submission] = $this->submissionWithAnswers($actor, false);

        $boolean = $version->questions()->where('type', QuestionType::Boolean->value)->firstOrFail();

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertSet('answers.'.$boolean->getKey(), '0');
    }

    #[Test]
    public function an_unanswered_boolean_stays_empty(): void
    {
        $actor = $this->admin();
        [$version, $submission] = $this->submissionWithAnswers($actor, null);

        $boolean = $version->questions()->where('type', QuestionType::Boolean->value)->firstOrFail();

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertSet('answers.'.$boolean->getKey(), null);
    }

    #[Test]
    public function a_number_renders_without_its_storage_scale(): void
    {
        // decimal:4 casts 18 to the string "18.0000". The scale is a storage
        // detail; the box the user reads should show the number they typed.
        $actor = $this->admin();
        [$version, $submission] = $this->submissionWithAnswers($actor, true);

        $number = $version->questions()->where('type', QuestionType::Number->value)->firstOrFail();

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertSet('answers.'.$number->getKey(), 18);
    }

    /*
    |--------------------------------------------------------------------------
    | Curriculum must stay shared curriculum
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customers_own_form_cannot_be_attached_to_shared_curriculum(): void
    {
        // The leak: CurriculumService::attachForm is general and does not check
        // the form's owner. The picker offers shared templates only, so this
        // was reachable solely by a tampered formTemplateId - and it would have
        // put one business's bespoke questions into the curriculum every batch
        // runs.
        $actor = $this->superAdmin();
        $customer = Customer::factory()->create();

        $program = Program::factory()->create();
        $sessionTemplate = SessionTemplate::factory()->create(['program_id' => $program->getKey()]);
        $theirs = FormTemplate::factory()->forCustomer((int) $customer->getKey())->create();

        Livewire::actingAs($actor)
            ->test(Curriculum::class)
            ->set('programId', $program->getKey())
            ->call('startFormAttachment', $sessionTemplate->getKey())
            ->set('formTemplateId', $theirs->getKey())
            ->call('attachForm')
            ->assertNotFound();

        $this->assertDatabaseCount('session_template_forms', 0);
    }

    #[Test]
    public function shared_curriculum_forms_still_attach_normally(): void
    {
        $actor = $this->superAdmin();

        $program = Program::factory()->create();
        $sessionTemplate = SessionTemplate::factory()->create(['program_id' => $program->getKey()]);
        $shared = FormTemplate::factory()->create(['customer_id' => null]);

        Livewire::actingAs($actor)
            ->test(Curriculum::class)
            ->set('programId', $program->getKey())
            ->call('startFormAttachment', $sessionTemplate->getKey())
            ->set('formTemplateId', $shared->getKey())
            ->call('attachForm');

        $this->assertDatabaseCount('session_template_forms', 1);
    }

    #[Test]
    public function the_admin_form_authoring_screen_cannot_reach_a_customers_own_template(): void
    {
        $customer = Customer::factory()->create();
        $theirs = FormTemplate::factory()->forCustomer((int) $customer->getKey())->create(['name' => 'Bespoke to Beta']);
        FormTemplate::factory()->create(['customer_id' => null, 'name' => 'Shared curriculum form']);

        Livewire::actingAs($this->superAdmin())
            ->test(FormTemplates::class)
            ->set('templateId', $theirs->getKey())
            ->assertDontSee('Bespoke to Beta')
            ->assertSee('Shared curriculum form')
            ->assertViewHas('template', null);
    }

    /*
    |--------------------------------------------------------------------------
    | An assignment belongs to a batch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_assignment_from_another_batch_cannot_be_submitted_against_this_enrolment(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $enrollment = $this->enrolment($customer);

        // A released assignment on a completely different batch.
        $other = AssignmentInstance::factory()->create();

        Livewire::actingAs($actor)
            ->test(CustomerAssignments::class, ['customer' => $customer])
            ->set('submittingEnrollmentId', $enrollment->getKey())
            ->set('submittingInstanceId', $other->getKey())
            ->set('body', 'Submitted against the wrong batch.')
            ->call('submit')
            ->assertNotFound();

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Performance regressions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_customer_directory_does_not_query_once_per_row_for_the_primary_contact(): void
    {
        // primaryContact() used to ignore an eager-loaded contacts relation and
        // issue a fresh query, so a twenty-row page cost twenty extra queries.
        $customers = Customer::factory()->count(5)->create();

        foreach ($customers as $customer) {
            CustomerContact::factory()->create([
                'customer_id' => $customer->getKey(),
                'is_primary' => true,
            ]);
        }

        $loaded = Customer::query()->with('contacts')->get();

        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach ($loaded as $customer) {
            $customer->primaryContact();
        }

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'The loaded relation must be used rather than re-queried per row.');
    }

    #[Test]
    public function primary_contact_still_resolves_when_the_relation_was_not_loaded(): void
    {
        $customer = Customer::factory()->create();
        CustomerContact::factory()->create(['customer_id' => $customer->getKey(), 'is_primary' => false]);
        $primary = CustomerContact::factory()->create(['customer_id' => $customer->getKey(), 'is_primary' => true]);

        $this->assertSame($primary->getKey(), $customer->fresh()->primaryContact()?->getKey());
    }

    #[Test]
    public function an_archived_contact_is_never_the_primary_contact_either_way(): void
    {
        // Both paths must agree, or a listing and a detail page would disagree
        // about who to contact.
        $customer = Customer::factory()->create();
        CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'is_primary' => true,
            'archived_at' => now(),
        ]);

        $this->assertNull($customer->fresh()->primaryContact());
        $this->assertNull(Customer::query()->with('contacts')->find($customer->getKey())->primaryContact());
    }

    /*
    |--------------------------------------------------------------------------
    | Layout contracts found by measuring a real browser
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function scroll_containers_can_shrink_below_their_content(): void
    {
        // A grid or flex child defaults to min-width:auto, so a wide table
        // inside a card could not shrink and widened the whole document
        // instead of scrolling within itself. Seven screens scrolled sideways
        // on a phone and clipped their content off the right edge.
        //
        // Asserted rather than left to a comment because the symptom is
        // invisible until somebody opens the page on a narrow screen.
        $this->assertStringContainsString(
            'min-w-0',
            file_get_contents(resource_path('views/components/ui/card.blade.php')),
        );

        $this->assertStringContainsString(
            'min-w-0',
            file_get_contents(resource_path('views/components/ui/table.blade.php')),
        );
    }

    #[Test]
    public function disabled_controls_are_styled_as_disabled(): void
    {
        // A submitted form is read-only, and its inputs were correctly
        // disabled - but rendered identically to editable ones, which invites
        // somebody to try typing into a record they cannot change.
        foreach (['input', 'select', 'textarea'] as $component) {
            $this->assertStringContainsString(
                'disabled:bg-slate-100',
                file_get_contents(resource_path("views/components/ui/{$component}.blade.php")),
                "ui.{$component} must show a disabled control as disabled.",
            );
        }
    }

    #[Test]
    public function no_livewire_component_shadows_a_framework_lifecycle_method(): void
    {
        // transition() is already a Livewire\Component method, and
        // hydrate{Property} silently registers as a lifecycle hook. Both were
        // real bugs here: one a fatal error, one an opaque
        // "Array to string conversion" on every request.
        $reserved = [];

        foreach ((new \ReflectionClass(Component::class))->getMethods() as $method) {
            $reserved[$method->getName()] = true;
        }

        unset($reserved['mount'], $reserved['render'], $reserved['__construct']);

        $hooks = ['hydrate', 'dehydrate', 'updating', 'updated', 'boot', 'booted', 'rendering', 'rendered'];
        $problems = [];

        foreach ($this->livewireSources() as $path) {
            $source = file_get_contents($path);
            $name = str_replace(app_path('Livewire').'/', '', $path);

            preg_match_all('/^\s*(?:public|protected|private)\s+function\s+(\w+)/m', $source, $methods);
            preg_match_all('/^\s*public\s+(?:\??[\w|]+\s+)?\$(\w+)/m', $source, $properties);

            $propertyNames = array_map(ucfirst(...), $properties[1]);

            foreach ($methods[1] as $method) {
                if (isset($reserved[$method])) {
                    $problems[] = "{$name}::{$method}() shadows Livewire\\Component::{$method}()";
                }

                foreach ($hooks as $hook) {
                    if ($method !== $hook && str_starts_with($method, $hook)
                        && in_array(substr($method, strlen($hook)), $propertyNames, true)
                        && ! str_starts_with($method, 'updated')) {
                        $problems[] = "{$name}::{$method}() silently registers as a {$hook} hook";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @return array<int, string>
     */
    private function livewireSources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Livewire')));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array{0: FormVersion, 1: FormSubmission}
     */
    private function submissionWithAnswers(User $actor, ?bool $booleanAnswer): array
    {
        $builder = app(FormBuilderService::class);

        $template = FormTemplate::factory()->create();
        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'Section', 0);
        $builder->addQuestion($version, $section, QuestionType::Number, 'Headcount', 0);
        $builder->addQuestion($version, $section, QuestionType::Boolean, 'Documented daily routine?', 1);
        $version = app(FormPublishingService::class)->publish($version, $actor);

        $customer = Customer::factory()->create();
        $submission = app(SubmissionService::class)
            ->startDraft($this->enrolment($customer), $version, $actor);

        $submissions = app(SubmissionService::class);
        $questions = $version->questions()->get();

        $submissions->answer($submission, $questions[0], ['value_number' => 18]);

        if ($booleanAnswer !== null) {
            $submissions->answer($submission, $questions[1], ['value_boolean' => $booleanAnswer]);
        } else {
            $submissions->answer($submission, $questions[1], ['value_boolean' => null]);
        }

        return [$version, $submission->fresh()];
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
