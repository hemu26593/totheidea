<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * One table within a report.
 *
 * Columns and rows are already computed and already formatted as strings by
 * the builder. Both renderers consume exactly this - so a figure cannot differ
 * between the PDF and the CSV, because neither of them does any arithmetic.
 *
 * NOTES CARRY WHAT COULD NOT BE COMPUTED. Where a figure depends on an
 * unresolved client decision, the section says so in a note instead of showing
 * a number nobody agreed. A report that quietly guessed would be worse than a
 * report that admits the gap.
 */
final readonly class ReportSection
{
    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public string $heading,
        public array $columns,
        public array $rows,
        public array $notes = [],
    ) {}

    /**
     * A section that carries only prose - a deferred calculation, or a
     * statement that there is nothing to show.
     *
     * @param  array<int, string>  $notes
     */
    public static function note(string $heading, array $notes): self
    {
        return new self($heading, [], [], $notes);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
