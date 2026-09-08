<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Renderers\CsvReportRenderer;
use App\Domain\Reporting\Renderers\PdfReportRenderer;
use App\Models\ReportArtifact;
use InvalidArgumentException;

/**
 * Turns a computed report into bytes, in the requested format.
 *
 * Both formats read the same ReportData. This class chooses a renderer; it
 * never computes, and neither renderer it delegates to computes either.
 */
class ReportRenderer
{
    public function __construct(
        private readonly PdfReportRenderer $pdf,
        private readonly CsvReportRenderer $csv,
    ) {}

    public function render(ReportData $data, string $format): string
    {
        return match ($format) {
            ReportArtifact::FORMAT_PDF => $this->pdf->render($data),
            ReportArtifact::FORMAT_CSV => $this->csv->render($data),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown report format [%s]. Expected one of: %s.',
                $format,
                implode(', ', ReportArtifact::FORMATS),
            )),
        };
    }
}
