<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\AiGenerationService;
use App\Domain\Ai\CustomerContextAssembler;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Enums\AiPurpose;
use App\Enums\QuestionType;
use App\Exceptions\CustomerIsolationException;
use App\Models\AiGeneration;
use App\Models\AiPromptVersion;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "AI must not access another customer's data."
 *
 * The tests below build TWO businesses with distinctive content and assert
 * that neither appears in the other's context - not by inspecting the
 * assembler's intentions, but by reading the bytes the provider was actually
 * handed.
 */
class AiCustomerIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function assembled_context_contains_only_the_named_customer(): void
    {
        $actor = $this->admin();

        $mine = $this->businessWithHistory('Alpha Metalworks', 'ALPHA-ONLY-MARKER');
        $theirs = $this->businessWithHistory('Beta Textiles', 'BETA-ONLY-MARKER');

        $context = app(CustomerContextAssembler::class)->forNarrative($mine, ['figure' => 1]);
        $rendered = $context->render();

        $this->assertSame((int) $mine->getKey(), $context->customerId);
        $this->assertStringContainsString('ALPHA-ONLY-MARKER', $rendered);
        $this->assertStringNotContainsString('BETA-ONLY-MARKER', $rendered);
        $this->assertStringNotContainsString('Beta Textiles', $rendered);
        $this->assertStringNotContainsString((string) $theirs->getKey(), (string) $context->customerId);
    }

    #[Test]
    public function the_provider_receives_only_one_business_worth_of_material(): void
    {
        $actor = $this->admin();

        $mine = $this->businessWithHistory('Alpha Metalworks', 'ALPHA-ONLY-MARKER');
        $this->businessWithHistory('Beta Textiles', 'BETA-ONLY-MARKER');

        $scenario = new AiScenario($actor);
        $double = AiScenario::bindProvider(AiScenario::validFormProposal());

        app(AiGenerationService::class)->generate(
            customer: $mine,
            prompt: $scenario->prompt,
            purpose: AiPurpose::Narrative,
            context: app(CustomerContextAssembler::class)->forNarrative($mine, ['figure' => 1]),
            actor: $actor,
            placeholders: ['business_name' => $mine->name],
        );

        $payload = $double->lastPayload();

        $this->assertStringContainsString('ALPHA-ONLY-MARKER', $payload);
        $this->assertStringNotContainsString('BETA-ONLY-MARKER', $payload);
        $this->assertStringNotContainsString('Beta Textiles', $payload);
    }

    #[Test]
    public function a_context_assembled_for_one_business_cannot_be_recorded_against_another(): void
    {
        // The one way isolation could be defeated in code above the
        // assembler: assembling for A and generating for B. Refused outright.
        $actor = $this->admin();
        $mine = $this->businessWithHistory('Alpha Metalworks', 'ALPHA');
        $theirs = $this->businessWithHistory('Beta Textiles', 'BETA');

        $scenario = new AiScenario($actor);
        AiScenario::bindProvider(AiScenario::validFormProposal());

        $this->expectException(CustomerIsolationException::class);

        app(AiGenerationService::class)->generate(
            customer: $theirs,
            prompt: $scenario->prompt,
            purpose: AiPurpose::Narrative,
            context: app(CustomerContextAssembler::class)->forNarrative($mine, []),
            actor: $actor,
        );
    }

    #[Test]
    public function a_generation_cannot_exist_without_naming_a_customer(): void
    {
        // NOT NULL in the schema, and refused by the model before the
        // database is even reached. There is no unscoped generation.
        $generation = new AiGeneration;
        $generation->forceFill([
            'ai_prompt_version_id' => AiPromptVersion::factory()->create()->getKey(),
            'purpose' => AiPurpose::Narrative,
            'input_context' => [],
            'generated_by' => $this->admin()->getKey(),
            'generated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/without a customer_id/');

        $generation->save();
    }

    #[Test]
    public function a_generation_cannot_be_reassigned_to_another_customer(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();

        $generation = AiGeneration::factory()->forCustomer($mine)->create();

        $this->expectException(\RuntimeException::class);

        $generation->forceFill(['customer_id' => $theirs->getKey()])->save();
    }

    #[Test]
    public function a_customer_scoped_template_cannot_be_drafted_from_another_customers_generation(): void
    {
        $actor = $this->admin();
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();

        $theirTemplate = FormTemplate::factory()->forCustomer((int) $theirs->getKey())->create();

        $generation = AiGeneration::factory()
            ->forCustomer($mine)
            ->succeeded(json_decode(AiScenario::validFormProposal(), true))
            ->create(['generated_by' => $actor->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        app(AiFormDraftService::class)->draftFrom($generation, $theirTemplate);
    }

    #[Test]
    public function every_context_query_is_constrained_by_customer_id(): void
    {
        // Reading the SQL the assembler actually issues. A query touching
        // customer data without its scope would show up here as a select
        // naming no customer_id at all.
        $mine = $this->businessWithHistory('Alpha Metalworks', 'ALPHA');
        $this->businessWithHistory('Beta Textiles', 'BETA');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(CustomerContextAssembler::class)->forNarrative($mine, []);

        $customerTouching = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'enrollments')
                || str_contains($sql, 'form_submissions')
                || str_contains($sql, 'submission_scores')
                || str_contains($sql, 'answers'),
        );

        $this->assertNotEmpty($customerTouching, 'The assembler must actually read something.');

        foreach ($customerTouching as $sql) {
            $this->assertStringContainsString(
                'customer_id',
                $sql,
                "A query over customer data carried no customer scope: {$sql}",
            );
        }
    }

    /**
     * A business with a marker string buried in its own free-text answers.
     */
    private function businessWithHistory(string $name, string $marker): Customer
    {
        $actor = User::factory()->create();

        $customer = Customer::factory()->create(['name' => $name]);
        $program = Program::factory()->create();
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        $template = FormTemplate::factory()->create();
        $version = $this->publishedVersionWithTextQuestion($template, $actor);

        $submission = app(SubmissionService::class)->startDraft($enrollment, $version, $actor);
        app(SubmissionService::class)->answer(
            $submission,
            $version->questions()->first(),
            ['value_text' => "Our biggest issue is {$marker}."],
        );
        app(SubmissionService::class)->submit($submission, $actor);

        return $customer;
    }

    private function publishedVersionWithTextQuestion(FormTemplate $template, User $actor): FormVersion
    {
        $builder = app(FormBuilderService::class);
        $version = $builder->createDraftVersion($template);
        $section = $builder->addSection($version, 'About', 0);
        $builder->addQuestion($version, $section, QuestionType::Textarea, 'Describe the business', 0);

        return app(FormPublishingService::class)->publish($version, $actor);
    }
}
