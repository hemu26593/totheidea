<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Contracts;

use App\Domain\Reporting\ReportData;
use Illuminate\Database\Eloquent\Model;

/**
 * One report, computed deterministically from operational data.
 *
 * A builder does arithmetic and nothing else: it does not render, it does not
 * write files, and it never asks a model for a number. Keeping calculation
 * apart from presentation is what lets the same figures be exported as a PDF
 * and as a CSV with no possibility of the two disagreeing.
 */
interface ReportBuilder
{
    /**
     * The report_key this builder produces. Part of every artifact record.
     */
    public function key(): string;

    /**
     * The kind of subject this report is about - an enrolment or a batch.
     *
     * @return class-string<Model>
     */
    public function subjectType(): string;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function build(Model $subject, array $parameters = []): ReportData;
}
