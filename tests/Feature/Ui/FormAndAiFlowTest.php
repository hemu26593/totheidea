<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\PromptVersionService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Enums\AiGenerationStatus;
use App\Enums\QuestionType;
use App\Livewire\Ai\ShowGeneration;
use App\Livewire\Customers\Ai as CustomerAi;
use App\Livewire\Customers\Forms as CustomerForms;
use App\Livewire\Forms\FormRenderer;
use App\Models\AiGeneration;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ai\AiScenario;
use Tests\TestCase;

/**
 * The form engine and the AI lifecycle, exercised through the UI.
 */
class FormAndAiFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function the_renderer_reads_whatever_the_published_version_contains(): void
    {
        // Nothing about any particular instrument is hard-coded: the same
        // component renders a form it has never seen before.
        $actor = $this->admin();
        $version = $this->publishedVersion($actor, 'Unheard-of instrument', [
            ['label' => 'How many people work here?', 'type' => QuestionType::Number],
            ['label' => 'Describe your busiest day', 'type' => QuestionType::Textarea],
            ['label' => 'Which area needs attention?', 'type' => QuestionType::SelectOne],
        ]);

        $submission = $this->submissionFor($version, $actor);

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->assertSee('How many people work here?')
            ->assertSee('Describe your busiest day')
            ->assertSee('Which area needs attention?')
            // The categorical set, added without numeric scores.
            ->assertSee('Average')
            ->assertSee('Best');
    }

    #[Test]
    public function answering_goes_through_the_domain_service(): void
    {
        $actor = $this->admin();
        $version = $this->publishedVersion($actor, 'Intake', [
            ['label' => 'Headcount', 'type' => QuestionType::Number],
        ]);
        $submission = $this->submissionFor($version, $actor);
        $question = $version->questions()->first();

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->set('answers.'.$question->getKey(), 12)
            ->call('saveAnswer', $question->getKey());

        // Written to the right column for the question's type, which is the
        // service's mapping rather than the component's.
        $this->assertDatabaseHas('answers', [
            'form_submission_id' => $submission->getKey(),
            'question_id' => $question->getKey(),
        ]);
        $this->assertEqualsWithDelta(
            12.0,
            (float) $submission->answers()->first()->value_number,
            0.001,
        );
    }

    #[Test]
    public function a_question_from_another_version_is_refused(): void
    {
        $actor = $this->admin();
        $version = $this->publishedVersion($actor, 'Mine', [['label' => 'Q', 'type' => QuestionType::Text]]);
        $other = $this->publishedVersion($actor, 'Theirs', [['label' => 'Other Q', 'type' => QuestionType::Text]]);

        $submission = $this->submissionFor($version, $actor);

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission])
            ->call('saveAnswer', $other->questions()->first()->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function a_submitted_form_cannot_be_edited_from_the_renderer(): void
    {
        $actor = $this->admin();
        $version = $this->publishedVersion($actor, 'Intake', [['label' => 'Q', 'type' => QuestionType::Text]]);
        $submission = $this->submissionFor($version, $actor);
        $question = $version->questions()->first();

        app(SubmissionService::class)->submit($submission, $actor);

        Livewire::actingAs($actor)
            ->test(FormRenderer::class, ['submission' => $submission->fresh()])
            ->set('answers.'.$question->getKey(), 'late edit')
            ->call('saveAnswer', $question->getKey())
            ->assertHasErrors('domain');

        $this->assertSame(0, $submission->answers()->count());
    }

    #[Test]
    public function only_published_versions_are_offered_when_starting_a_form(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $this->enrolment($customer);

        $draftOnly = FormTemplate::factory()->create(['name' => 'Draft only template']);
        app(FormBuilderService::class)->createDraftVersion($draftOnly);

        $published = $this->publishedVersion($actor, 'Published template', [
            ['label' => 'Q', 'type' => QuestionType::Text],
        ]);

        Livewire::actingAs($actor)
            ->test(CustomerForms::class, ['customer' => $customer])
            ->call('startSubmission')
            ->assertSee('Published template')
            ->assertDontSee('Draft only template');
    }

    #[Test]
    public function ai_generation_produces_a_draft_and_never_publishes(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $customer = Customer::factory()->create();

        $this->publishFormDraftPrompt($generator);
        AiScenario::bindProvider(AiScenario::validFormProposal());

        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $customer])
            ->set('brief', 'A short operations intake.')
            ->call('generate');

        $generation = AiGeneration::query()->firstOrFail();
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->status);

        // Generating publishes nothing.
        $this->assertSame(0, FormVersion::query()->where('status', 'published')->count());

        $template = FormTemplate::factory()->create();

        Livewire::actingAs($generator)
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->set('draftTemplateId', $template->getKey())
            ->call('draft');

        $version = $generation->fresh()->resultingFormVersion;

        $this->assertTrue($version->isDraft(), 'Drafting produces a draft and stops.');
        $this->assertSame(AiGenerationStatus::AwaitingApproval, $generation->fresh()->status);
    }

    #[Test]
    public function a_generator_cannot_approve_their_own_generation_through_the_ui(): void
    {
        $generator = $this->admin();
        $generation = $this->awaitingApproval($generator);

        Livewire::actingAs($generator)
            ->test(ShowGeneration::class, ['generation' => $generation])
            // The rule is explained rather than hidden.
            ->assertViewHas('viewerIsGenerator', true)
            ->assertViewHas('canDecide', false)
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(AiGenerationStatus::AwaitingApproval, $generation->fresh()->status);
        $this->assertDatabaseCount('ai_approvals', 0);
    }

    #[Test]
    public function a_super_admin_cannot_approve_their_own_generation_through_the_ui(): void
    {
        // The case Gate::before would wave through if `approve` were not a
        // guarded ability.
        $superAdmin = $this->superAdmin();
        $generation = $this->awaitingApproval($superAdmin);

        Livewire::actingAs($superAdmin)
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->assertViewHas('canDecide', false)
            ->call('approve')
            ->assertForbidden();

        $this->assertDatabaseCount('ai_approvals', 0);
    }

    #[Test]
    public function a_second_person_approves_and_that_publishes_the_draft(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $generation = $this->awaitingApproval($generator);

        Livewire::actingAs($approver)
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->assertViewHas('canDecide', true)
            ->set('remark', 'Reads well.')
            ->call('approve');

        $generation->refresh();

        $this->assertSame(AiGenerationStatus::Approved, $generation->status);
        $this->assertTrue($generation->resultingFormVersion->isPublished());
        $this->assertSame($approver->getKey(), $generation->resultingFormVersion->published_by);
    }

    #[Test]
    public function the_generation_screen_exposes_no_provider_credentials(): void
    {
        config(['ai.anthropic.api_key' => 'sk-ant-super-secret-value']);

        $generation = $this->awaitingApproval($this->admin());

        Livewire::actingAs($this->admin())
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->assertDontSee('sk-ant')
            ->assertDontSee('api_key')
            ->assertDontSee('ANTHROPIC_API_KEY');
    }

    private function awaitingApproval(User $generator): AiGeneration
    {
        $customer = Customer::factory()->create();

        $this->publishFormDraftPrompt($generator);
        AiScenario::bindProvider(AiScenario::validFormProposal());

        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $customer])
            ->set('brief', 'A short operations intake.')
            ->call('generate');

        $generation = AiGeneration::query()->latest('id')->firstOrFail();

        app(AiFormDraftService::class)->draftFrom($generation, FormTemplate::factory()->create());

        return $generation->fresh();
    }

    private function publishFormDraftPrompt(User $author): void
    {
        $prompts = app(PromptVersionService::class);

        $prompts->publish(
            $prompts->createDraft(
                key: 'form_draft',
                template: 'Propose an intake form for {{ business_name }}.',
                author: $author,
                outputSchema: AiFormDraftService::outputSchema(),
            ),
            $author,
        );
    }

    /**
     * @param  array<int, array{label: string, type: QuestionType}>  $questions
     */
    private function publishedVersion(User $actor, string $name, array $questions): FormVersion
    {
        $builder = app(FormBuilderService::class);

        $template = FormTemplate::factory()->create(['name' => $name]);
        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'Section', 0);

        foreach ($questions as $index => $question) {
            $created = $builder->addQuestion($version, $section, $question['type'], $question['label'], $index);

            if ($question['type'] === QuestionType::SelectOne) {
                $builder->addScoringCategoryOptions($created);
            }
        }

        return app(FormPublishingService::class)->publish($version, $actor);
    }

    private function submissionFor(FormVersion $version, User $actor): FormSubmission
    {
        $customer = Customer::factory()->create();

        return app(SubmissionService::class)
            ->startDraft($this->enrolment($customer), $version, $actor);
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
