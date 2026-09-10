<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiApprovalService;
use App\Domain\Ai\AiNarrativeService;
use App\Domain\Ai\PromptVersionService;
use App\Domain\Reporting\Builders\ParticipantProgressReport;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Reporting\ReportScenario;
use Tests\TestCase;

/**
 * AI may attach prose to a report. It can never attach a figure.
 *
 * Phase 8 left the narrative slot empty with that note. These tests hold the
 * line now that something can fill it.
 */
class AiReportNarrativeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function attaching_a_narrative_leaves_every_computed_figure_identical(): void
    {
        $actor = $this->admin();
        $scenario = new ReportScenario($this->admin());

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $before = $data->toArray()['sections'];

        $withNarrative = $data->withNarrative('The business has made steady progress this quarter.');

        $this->assertSame($before, $withNarrative->toArray()['sections']);
        $this->assertNotSame($data, $withNarrative, 'withNarrative returns a new instance.');
        $this->assertNull($data->narrative, 'The original payload is untouched.');
    }

    #[Test]
    public function only_an_approved_narrative_is_attached(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new ReportScenario($this->admin());

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $service = app(AiNarrativeService::class);

        foreach ([
            AiGenerationStatus::Pending,
            AiGenerationStatus::Succeeded,
            AiGenerationStatus::Failed,
            AiGenerationStatus::AwaitingApproval,
            AiGenerationStatus::Rejected,
        ] as $status) {
            $generation = AiGeneration::factory()->create([
                'purpose' => AiPurpose::Narrative,
                'status' => $status,
                'raw_output' => 'Unreviewed prose that must not reach a client.',
                'generated_by' => $generator->getKey(),
            ]);

            $this->assertNull(
                $service->attach($data, $generation)->narrative,
                "A [{$status->value}] generation must not put prose in front of a client.",
            );
        }

        $approved = AiGeneration::factory()->create([
            'purpose' => AiPurpose::Narrative,
            'status' => AiGenerationStatus::Approved,
            'raw_output' => 'Reviewed prose.',
            'generated_by' => $generator->getKey(),
        ]);

        $this->assertSame('Reviewed prose.', $service->attach($data, $approved)->narrative);
    }

    #[Test]
    public function a_form_draft_generation_is_never_mistaken_for_a_narrative(): void
    {
        $scenario = new ReportScenario($this->admin());
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $formDraft = AiGeneration::factory()->create([
            'purpose' => AiPurpose::FormDraft,
            'status' => AiGenerationStatus::Approved,
            'raw_output' => '{"title":"a form"}',
        ]);

        $this->assertNull(app(AiNarrativeService::class)->attach($data, $formDraft)->narrative);
    }

    #[Test]
    public function a_report_with_no_narrative_is_still_a_complete_report(): void
    {
        $scenario = new ReportScenario($this->admin());
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $this->assertNull(app(AiNarrativeService::class)->attach($data, null)->narrative);
        $this->assertNotSame([], $data->sections);
    }

    #[Test]
    public function a_requested_narrative_waits_for_a_person_before_it_can_be_attached(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $scenario = new ReportScenario($this->admin());

        $service = app(PromptVersionService::class);
        $service->publish(
            $service->createDraft(
                AiNarrativeService::PROMPT_KEY,
                'Describe the figures for {{ report_title }} in three sentences.',
                $generator,
            ),
            $generator,
        );

        AiScenario::bindProvider('The business attended every session and submitted on time.');

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $customer = $scenario->customers[0];

        $generation = app(AiNarrativeService::class)->requestFor($data, $customer, $generator);

        $this->assertSame(AiGenerationStatus::AwaitingApproval, $generation->status);
        $this->assertNull(app(AiNarrativeService::class)->attach($data, $generation)->narrative);

        app(AiApprovalService::class)->approve($generation, $approver);

        $attached = app(AiNarrativeService::class)->attach($data, $generation->fresh());
        $this->assertStringContainsString('attended every session', (string) $attached->narrative);
        // And the figures are the same ones the builder produced.
        $this->assertSame($data->toArray()['sections'], $attached->toArray()['sections']);
    }

    #[Test]
    public function the_narrative_prompt_receives_the_computed_figures_and_is_told_not_to_recompute(): void
    {
        $generator = $this->admin();
        $scenario = new ReportScenario($this->admin());

        $service = app(PromptVersionService::class);
        $service->publish(
            $service->createDraft(
                AiNarrativeService::PROMPT_KEY,
                'Describe {{ report_title }}.',
                $generator,
            ),
            $generator,
        );

        $double = AiScenario::bindProvider('Prose.');

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        app(AiNarrativeService::class)->requestFor($data, $scenario->customers[0], $generator);

        $request = $double->lastRequest();

        $this->assertStringContainsString('authoritative, do not recompute', $request->context);
        $this->assertStringContainsString('Do not calculate, total, average, rank or band', $request->instructions);
        // The report's own heading travels as reading material.
        $this->assertStringContainsString($data->sections[0]->heading, $request->context);
    }

    #[Test]
    public function a_narrative_generation_holds_no_schema_and_therefore_no_structure(): void
    {
        // Prose has no shape to validate, so validated_output stays null and
        // nothing downstream can mistake a narrative for data.
        $generator = $this->admin();
        $scenario = new ReportScenario($this->admin());

        $service = app(PromptVersionService::class);
        $service->publish(
            $service->createDraft(AiNarrativeService::PROMPT_KEY, 'Describe {{ report_title }}.', $generator),
            $generator,
        );

        AiScenario::bindProvider('Prose about the quarter.');

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $generation = app(AiNarrativeService::class)->requestFor($data, $scenario->customers[0], $generator);

        $this->assertNull($generation->validated_output);
        $this->assertSame('Prose about the quarter.', $generation->raw_output);
    }
}
