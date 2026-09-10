<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * THE AUTHORITATIVE REPORT PAYLOAD.
 *
 * Every number a report shows lives here, computed in PHP from operational
 * data by a builder. The PDF renderer and the CSV renderer both read this
 * structure and neither computes anything, which is what guarantees the two
 * formats agree.
 *
 * THE NARRATIVE SLOT IS DELIBERATELY EMPTY. Phase 9 may attach prose to a
 * report; it can never attach a figure. $narrative is text and is rendered
 * alongside the tables, never in place of a value in one - and every report
 * renders correctly with it absent, which a test asserts.
 */
final readonly class ReportData
{
    /**
     * @param  array<int, ReportSection>  $sections
     * @param  array<string, mixed>  $parameters  the filters that produced this
     */
    public function __construct(
        public string $reportKey,
        public string $title,
        public Model $subject,
        public int $customerId,
        public CarbonImmutable $generatedAt,
        public array $sections,
        public array $parameters = [],
        /**
         * Reserved for a Phase 9 narrative. Always null in this phase, and
         * never a source of any figure above.
         */
        public ?string $narrative = null,
    ) {}

    /**
     * A copy carrying a narrative.
     *
     * Returns a new instance rather than mutating: the computed sections are
     * the deliverable, and nothing that attaches prose may touch them.
     */
    public function withNarrative(?string $narrative): self
    {
        return new self(
            $this->reportKey,
            $this->title,
            $this->subject,
            $this->customerId,
            $this->generatedAt,
            $this->sections,
            $this->parameters,
            $narrative,
        );
    }

    /**
     * The structured payload, for storage as report parameters and for tests
     * that compare a rendered file against the data behind it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'report_key' => $this->reportKey,
            'title' => $this->title,
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => (int) $this->subject->getKey(),
            'customer_id' => $this->customerId,
            'generated_at' => $this->generatedAt->toIso8601String(),
            'parameters' => $this->parameters,
            'sections' => array_map(fn (ReportSection $s): array => [
                'heading' => $s->heading,
                'columns' => $s->columns,
                'rows' => $s->rows,
                'notes' => $s->notes,
            ], $this->sections),
        ];
    }
}
