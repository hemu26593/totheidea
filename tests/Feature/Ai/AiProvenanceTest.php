<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiGenerationService;
use App\Domain\Ai\CustomerContextAssembler;
use App\Domain\Ai\PromptVersionService;
use App\Enums\AiPromptStatus;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use App\Models\AiPromptVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * "An output cannot be explained months later" is the failure this table
 * exists to prevent. These tests are about whether it still can be.
 */
class AiProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function a_generation_names_the_exact_prompt_version_it_used(): void
    {
        $actor = $this->admin();
        $scenario = new AiScenario($actor);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = app(AiGenerationService::class)->generate(
            customer: $scenario->customer,
            prompt: $scenario->prompt,
            purpose: AiPurpose::FormDraft,
            context: app(CustomerContextAssembler::class)->forFormDraft($scenario->customer, 'brief'),
            actor: $actor,
            placeholders: ['business_name' => $scenario->customer->name],
        );

        $this->assertSame($scenario->prompt->getKey(), $generation->ai_prompt_version_id);
        $this->assertSame($actor->getKey(), $generation->generated_by);
        $this->assertNotNull($generation->generated_at);
    }

    #[Test]
    public function the_input_context_snapshot_records_what_the_model_saw(): void
    {
        $actor = $this->admin();
        $scenario = new AiScenario($actor);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = app(AiGenerationService::class)->generate(
            customer: $scenario->customer,
            prompt: $scenario->prompt,
            purpose: AiPurpose::FormDraft,
            context: app(CustomerContextAssembler::class)->forFormDraft($scenario->customer, 'a short intake'),
            actor: $actor,
            placeholders: ['business_name' => $scenario->customer->name],
        );

        $this->assertIsArray($generation->input_context);
        $this->assertSame((int) $scenario->customer->getKey(), $generation->input_context['customer_id']);
        $this->assertSame('a short intake', $generation->input_context['facts']['brief']);
    }

    #[Test]
    public function the_snapshot_is_written_once_and_never_rewritten(): void
    {
        $generation = AiGeneration::factory()->create();

        foreach (['input_context', 'customer_id', 'ai_prompt_version_id', 'generated_by', 'purpose'] as $column) {
            $fresh = $generation->fresh();
            $original = $fresh->getAttribute($column);

            // Derived from the CURRENT value so it is always different. A fixed
            // literal silently matched the existing id for the foreign keys,
            // leaving nothing dirty - so the save was a no-op that no guard had
            // any reason to refuse, and two of these columns were never
            // actually tested.
            $replacement = match ($column) {
                'input_context' => ['tampered' => true],
                'purpose' => $fresh->purpose === AiPurpose::Summary ? AiPurpose::FormDraft : AiPurpose::Summary,
                default => ((int) $original) + 1,
            };

            $this->assertNotEquals($original, $replacement, "The probe for [{$column}] must actually change it.");

            try {
                $fresh->forceFill([$column => $replacement])->save();
                $this->fail("[{$column}] must be fixed once the generation exists.");
            } catch (RuntimeException) {
                // Two guards can refuse this - the model's own, and
                // BelongsToCustomer for customer_id - and which one speaks
                // first is not the rule under test. The rule is that the write
                // is refused and the record is unchanged.
                $this->assertEquals(
                    $original,
                    $generation->fresh()->getAttribute($column),
                    "[{$column}] was refused but changed anyway.",
                );
            }
        }
    }

    #[Test]
    public function nothing_in_the_application_reads_a_figure_back_out_of_a_snapshot(): void
    {
        // The same rule report artifacts follow. A snapshot answers "what did
        // we show?", never "what is true now?". Asserted by reading the
        // source: input_context is written by AiGenerationService and read
        // nowhere else.
        $readers = [];

        foreach ($this->phpSources() as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, 'input_context')) {
                continue;
            }

            $readers[] = str_replace(base_path().'/', '', $file);
        }

        sort($readers);

        $this->assertSame([
            'app/Domain/Ai/AiGenerationService.php',
            'app/Models/AiGeneration.php',
            // A docblock reference, in the class that explains why the
            // snapshot lives on the generation rather than in the prompt.
            'app/Models/AiPromptVersion.php',
            'database/factories/AiGenerationFactory.php',
            'database/migrations/2026_09_07_120042_create_ai_generations_table.php',
        ], $readers, 'input_context is provenance. Reading it into business logic would make a snapshot authoritative.');
    }

    #[Test]
    public function a_generation_is_never_deleted_even_when_it_failed(): void
    {
        $generation = AiGeneration::factory()->failed()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never deleted/');

        $generation->delete();
    }

    #[Test]
    public function a_published_prompt_cannot_be_rewritten(): void
    {
        $author = $this->admin();
        $service = app(PromptVersionService::class);

        $prompt = $service->publish(
            $service->createDraft('form_draft', 'Draft for {{ business_name }}.', $author),
            $author,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be rewritten/');

        $prompt->forceFill(['template' => 'Something else entirely.'])->save();
    }

    #[Test]
    public function a_prompt_version_is_never_deleted(): void
    {
        $prompt = AiPromptVersion::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never deleted/');

        $prompt->delete();
    }

    #[Test]
    public function publishing_the_next_version_archives_the_previous_one(): void
    {
        $author = $this->admin();
        $service = app(PromptVersionService::class);

        $first = $service->publish($service->createDraft('form_draft', 'v1 {{ a }}', $author), $author);
        $second = $service->publish($service->createDraft('form_draft', 'v2 {{ a }}', $author), $author);

        $this->assertSame(AiPromptStatus::Archived, $first->fresh()->status);
        $this->assertSame(AiPromptStatus::Published, $second->fresh()->status);
        $this->assertSame(2, (int) $second->version_number);

        // And resolution picks the current one.
        $this->assertSame($second->getKey(), $service->publishedFor('form_draft')->getKey());
    }

    #[Test]
    public function generation_is_refused_when_no_prompt_has_been_published(): void
    {
        // Never against an unreviewed draft.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No published prompt version/');

        app(PromptVersionService::class)->publishedFor('never_written');
    }

    #[Test]
    public function generations_carry_no_actor_triple(): void
    {
        // AI generation is always initiated by an internal user; an external
        // access grant can never invoke it. The absence of these columns is
        // what makes that structural.
        foreach (['source', 'access_grant_id', 'created_by'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('ai_generations', $column),
                "ai_generations must not carry [{$column}] - AI is never invoked through an access grant."
            );
            $this->assertFalse(Schema::hasColumn('ai_approvals', $column));
        }

        $columns = collect(Schema::getColumns('ai_generations'))->keyBy('name');
        $this->assertFalse($columns['generated_by']['nullable'], 'generated_by is always an internal user.');
        $this->assertFalse($columns['customer_id']['nullable'], 'There is no unscoped generation.');
    }

    #[Test]
    public function one_decision_per_generation_is_a_database_constraint(): void
    {
        $unique = collect(Schema::getIndexes('ai_approvals'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns']);

        $this->assertTrue($unique->contains(['ai_generation_id']));

        $promptUnique = collect(Schema::getIndexes('ai_prompt_versions'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns']);

        $this->assertTrue($promptUnique->contains(['key', 'version_number']));
    }

    #[Test]
    public function generations_carry_no_unique_key_because_repeated_generation_is_legitimate(): void
    {
        $unique = collect(Schema::getIndexes('ai_generations'))
            ->filter(fn (array $i): bool => $i['unique'] === true && $i['columns'] !== ['id']);

        $this->assertCount(0, $unique);
    }

    /**
     * @return array<int, string>
     */
    private function phpSources(): array
    {
        $files = [];

        foreach ([app_path(), base_path('database'), base_path('config'), base_path('routes')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
