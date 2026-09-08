<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiApprovalService;
use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\AiGenerationService;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\CustomerContextAssembler;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Enums\AuditAction;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SessionAttendance;
use App\Models\SubmissionScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Generate -> Validate -> Draft -> Preview -> Approve -> Publish.
 *
 * The stages are load-bearing and are tested as stages: each one leaves the
 * generation in a state the next one requires, and no stage can be skipped.
 */
class AiLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function the_full_lifecycle_produces_a_published_form_authored_by_two_people(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        // GENERATE + VALIDATE
        $generation = app(AiGenerationService::class)->generate(
            customer: $scenario->customer,
            prompt: $scenario->prompt,
            purpose: AiPurpose::FormDraft,
            context: app(CustomerContextAssembler::class)
                ->forFormDraft($scenario->customer, 'A short operations intake.'),
            actor: $generator,
            placeholders: ['business_name' => $scenario->customer->name],
        );

        $this->assertSame(AiGenerationStatus::Succeeded, $generation->status);
        $this->assertIsArray($generation->validated_output);
        $this->assertSame(42, $generation->tokens_used);

        // DRAFT
        $version = app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);

        $this->assertTrue($version->isDraft(), 'Generation produces a draft and stops there.');
        $this->assertSame(
            AiGenerationStatus::AwaitingApproval,
            $generation->fresh()->status,
            'A drafted generation is waiting for a person.',
        );
        $this->assertSame(2, $version->questions()->count());

        // APPROVE + PUBLISH, by somebody else
        app(AiApprovalService::class)->approve($generation->fresh(), $approver, 'Reads well.');

        $version->refresh();
        $this->assertTrue($version->isPublished());
        $this->assertSame($approver->getKey(), $version->published_by);
        $this->assertSame(AiGenerationStatus::Approved, $generation->fresh()->status);
    }

    #[Test]
    public function an_ai_authored_form_is_answered_and_scored_by_exactly_the_same_code(): void
    {
        // The point of drafting inside the ordinary form engine: nothing
        // downstream can tell an AI-authored version from a hand-authored one.
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = $this->generate($scenario, $generator);
        $version = app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);
        app(AiApprovalService::class)->approve($generation->fresh(), $approver);

        $program = Program::factory()->create();
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $enrollment = Enrollment::factory()->create([
            'customer_id' => $scenario->customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        $submission = app(SubmissionService::class)->startDraft(
            $enrollment,
            app(FormPublishingService::class)->versionForNewSubmission($scenario->template->fresh()),
            $approver,
        );

        $numberQuestion = $version->fresh()->questions()
            ->where('type', QuestionType::Number->value)
            ->firstOrFail();

        app(SubmissionService::class)->answer($submission, $numberQuestion, ['value_number' => 12]);
        app(SubmissionService::class)->submit($submission, $approver);

        $this->assertSame(1, $submission->fresh()->answers()->count());
        $this->assertDatabaseHas('answers', [
            'form_submission_id' => $submission->getKey(),
            'question_id' => $numberQuestion->getKey(),
        ]);
    }

    #[Test]
    public function output_that_fails_validation_is_recorded_as_failed_and_persists_nothing(): void
    {
        $generator = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider('{"title":"Broken","sections":"not an array"}');

        $generation = $this->generate($scenario, $generator);

        $this->assertSame(AiGenerationStatus::Failed, $generation->status);
        $this->assertNull($generation->validated_output, 'validated_output means "this conformed".');
        $this->assertStringContainsString('expected array', (string) $generation->validation_error);
        // The raw output IS kept: reviewing what the model actually said is
        // how the prompt gets fixed.
        $this->assertNotNull($generation->raw_output);
    }

    #[Test]
    public function a_failed_generation_cannot_become_a_draft(): void
    {
        $generator = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider('not json at all');

        $generation = $this->generate($scenario, $generator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot become a draft/');

        app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);
    }

    #[Test]
    public function a_provider_outage_is_recorded_as_a_failure_rather_than_lost(): void
    {
        $generator = $this->admin();
        $scenario = new AiScenario($generator);

        // No provider configured at all - the default posture.
        app()->forgetInstance(AiProvider::class);
        config(['ai.provider' => 'null']);

        $generation = $this->generate($scenario, $generator);

        $this->assertSame(AiGenerationStatus::Failed, $generation->status);
        $this->assertStringContainsString('No AI provider is configured', (string) $generation->validation_error);
        // The provenance row exists even though nothing was ever returned.
        $this->assertDatabaseHas('ai_generations', ['id' => $generation->getKey()]);
    }

    #[Test]
    public function a_generation_cannot_be_approved_before_it_has_produced_anything(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = $this->generate($scenario, $generator);

        // Succeeded, but not drafted: there is nothing for anyone to look at.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has produced something to review/');

        app(AiApprovalService::class)->approve($generation, $approver);
    }

    #[Test]
    public function only_one_decision_is_ever_recorded_for_a_generation(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = $this->generate($scenario, $generator);
        app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);

        app(AiApprovalService::class)->approve($generation->fresh(), $approver);

        // A reversal is a new generation, not a second approval.
        $this->expectException(RuntimeException::class);

        app(AiApprovalService::class)->reject($generation->fresh(), $approver);
    }

    #[Test]
    public function a_rejected_generation_leaves_its_draft_unpublished(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = $this->generate($scenario, $generator);
        $version = app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);

        app(AiApprovalService::class)->reject($generation->fresh(), $approver, 'Wrong emphasis.');

        $this->assertTrue($version->fresh()->isDraft(), 'A rejected proposal is never published.');
        $this->assertSame(AiGenerationStatus::Rejected, $generation->fresh()->status);
        // And it is not tidied away: what was proposed and turned down stays
        // readable.
        $this->assertDatabaseHas('form_versions', ['id' => $version->getKey()]);
    }

    #[Test]
    public function generation_and_its_decision_are_both_audited(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = $this->generate($scenario, $generator);
        app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);
        app(AiApprovalService::class)->approve($generation->fresh(), $approver);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AiGenerated->value,
            'actor_id' => $generator->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AiApproved->value,
            'actor_id' => $approver->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AiPromptPublished->value,
        ]);

        // The audit trail names the two different people, which is the whole
        // record of the rule having been observed.
        $this->assertNotSame(
            AuditLog::query()->where('action', AuditAction::AiGenerated->value)->value('actor_id'),
            AuditLog::query()->where('action', AuditAction::AiApproved->value)->value('actor_id'),
        );
    }

    #[Test]
    public function generation_never_writes_a_score(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = $this->generate($scenario, $generator);
        $version = app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);
        app(AiApprovalService::class)->approve($generation->fresh(), $approver);

        $this->assertSame(0, SubmissionScore::query()->count(), 'AI never writes submission_scores.');
        $this->assertSame(0, MmdEntry::query()->count());
        $this->assertSame(0, MmdTarget::query()->count());
        $this->assertSame(0, SessionAttendance::query()->count());

        // Nor does it set a numeric option value. Average / Good / Better /
        // Best have no numeric mapping and no model gets to invent one.
        $this->assertSame(
            0,
            QuestionOption::query()->whereNotNull('score_value')->count(),
        );
        // Nor a per-question maximum, nor an expression.
        $this->assertSame(0, Question::query()
            ->where('form_version_id', $version->getKey())
            ->whereNotNull('max_score')
            ->count());
        $this->assertSame(0, Question::query()
            ->where('form_version_id', $version->getKey())
            ->whereNotNull('compute_expression')
            ->count());
    }

    private function generate(AiScenario $scenario, User $actor): AiGeneration
    {
        return app(AiGenerationService::class)->generate(
            customer: $scenario->customer,
            prompt: $scenario->prompt,
            purpose: AiPurpose::FormDraft,
            context: app(CustomerContextAssembler::class)
                ->forFormDraft($scenario->customer, 'A short operations intake.'),
            actor: $actor,
            placeholders: ['business_name' => $scenario->customer->name],
        );
    }
}
