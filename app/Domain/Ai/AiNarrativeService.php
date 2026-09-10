<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportSection;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use App\Models\Customer;
use App\Models\User;

/**
 * Prose ALONGSIDE a report's figures. Never instead of one.
 *
 * ReportData left a narrative slot deliberately empty in Phase 8, with the
 * note that a later phase could attach prose but never a figure. This is that
 * phase, and the boundary is kept structurally rather than by intention:
 *
 *   - withNarrative() returns a NEW ReportData carrying the same sections.
 *     The computed sections are readonly and are passed through untouched, so
 *     there is no expression in this class that could alter a number.
 *   - The model is given the finished figures as reading material and is
 *     asked to describe them. It is not asked to compute, and its answer is
 *     never parsed for values.
 *   - A failed or unapproved generation yields NO narrative, and every report
 *     renders correctly without one - which Phase 8 asserts.
 *
 * WHY THE NARRATIVE IS APPROVED LIKE ANYTHING ELSE. Prose about a business's
 * performance goes to that business. It passes through the same
 * generate/validate/approve lifecycle as a form draft, so a person has read
 * it before a client does.
 */
class AiNarrativeService
{
    public function __construct(
        private readonly AiGenerationService $generations,
        private readonly CustomerContextAssembler $assembler,
        private readonly PromptVersionService $prompts,
    ) {}

    /**
     * The prompt key a narrative is generated from.
     */
    public const PROMPT_KEY = 'diagnostic_narrative';

    /**
     * Ask for a narrative describing a report that has already been computed.
     *
     * The generation is returned rather than the text: the caller has to
     * decide what to do with a failure, and nothing may attach unapproved
     * prose to a delivered report.
     */
    public function requestFor(ReportData $data, Customer $customer, User $actor): AiGeneration
    {
        $context = $this->assembler->forNarrative($customer, $this->readableFigures($data));

        $generation = $this->generations->generate(
            customer: $customer,
            prompt: $this->prompts->publishedFor(self::PROMPT_KEY),
            purpose: AiPurpose::Narrative,
            context: $context,
            actor: $actor,
            placeholders: ['report_title' => $data->title],
        );

        // A narrative has prose output, so there is nothing to draft - but it
        // still needs a person to read it before it reaches a client.
        if ($generation->status === AiGenerationStatus::Succeeded) {
            $generation->forceFill(['status' => AiGenerationStatus::AwaitingApproval])->save();
            $generation->refresh();
        }

        return $generation;
    }

    /**
     * Attach APPROVED prose to a report.
     *
     * Anything else - failed, awaiting approval, rejected - returns the
     * report exactly as it was. A report with no narrative is a complete
     * report; a report with unreviewed prose is not.
     */
    public function attach(ReportData $data, ?AiGeneration $generation): ReportData
    {
        if ($generation === null
            || $generation->status !== AiGenerationStatus::Approved
            || $generation->purpose !== AiPurpose::Narrative) {
            return $data;
        }

        $text = trim((string) $generation->raw_output);

        return $text === '' ? $data : $data->withNarrative($text);
    }

    /**
     * The report's own figures, flattened for reading.
     *
     * A one-way projection: the model sees what the tables show and has no
     * route back into them. Nothing here recomputes a value - every number is
     * the string the deterministic builder already produced.
     *
     * @return array<string, mixed>
     */
    private function readableFigures(ReportData $data): array
    {
        return [
            'report_key' => $data->reportKey,
            'title' => $data->title,
            'generated_at' => $data->generatedAt->toDateString(),
            'sections' => array_map(
                static fn (ReportSection $section): array => [
                    'heading' => $section->heading,
                    'columns' => $section->columns,
                    'rows' => $section->rows,
                    'notes' => $section->notes,
                ],
                $data->sections,
            ),
        ];
    }
}
