<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Renderers;

use App\Domain\Reporting\Pdf\SimplePdfDocument;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportSection;

/**
 * ReportData -> PDF bytes.
 *
 * PRESENTATION ONLY. This class reads the payload a builder computed and
 * arranges it on a page. It performs no arithmetic, reads no model and touches
 * no database - so a PDF cannot show a figure the CSV does not, and neither
 * can drift from what the builder decided.
 *
 * The narrative slot is rendered when present and simply absent when not,
 * which is what lets Phase 9 attach prose later without this class changing.
 */
class PdfReportRenderer
{
    public function render(ReportData $data): string
    {
        $pdf = new SimplePdfDocument;

        $pdf->text($data->title, 16, true);
        $pdf->gap(2);
        $pdf->text('Generated '.$data->generatedAt->toDayDateTimeString(), 9);
        $pdf->text(sprintf(
            '%s #%d',
            class_basename($data->subject::class),
            $data->subject->getKey(),
        ), 9);
        $pdf->rule();

        if ($data->narrative !== null && trim($data->narrative) !== '') {
            // Prose, alongside the figures below - never in place of one.
            $pdf->gap(4);
            $pdf->text('Summary', 12, true);
            $pdf->gap(2);
            $pdf->paragraph($data->narrative);
            $pdf->rule();
        }

        foreach ($data->sections as $section) {
            $this->section($pdf, $section);
        }

        return $pdf->render();
    }

    private function section(SimplePdfDocument $pdf, ReportSection $section): void
    {
        $pdf->gap(6);
        $pdf->text($section->heading, 12, true);
        $pdf->gap(2);

        if (! $section->isEmpty()) {
            $widths = $this->columnWidths($section, $pdf->usableWidth());

            $pdf->text($this->row($section->columns, $widths), 9, true);

            foreach ($section->rows as $row) {
                $pdf->text($this->row($row, $widths), 9);
            }
        }

        foreach ($section->notes as $note) {
            $pdf->gap(2);
            $pdf->paragraph($note, 8);
        }
    }

    /**
     * Even columns, sized to fit the page.
     *
     * Deliberately simple: these reports are tables of short values, and a
     * measured layout engine would be a large amount of machinery for the sake
     * of ragged edges nobody has asked to remove.
     *
     * @return array<int, int> character budget per column
     */
    private function columnWidths(ReportSection $section, float $usableWidth): array
    {
        $count = max(1, count($section->columns));
        // Helvetica at 9pt averages ~4.5pt per character.
        $totalChars = (int) floor($usableWidth / 4.5);
        $per = max(8, (int) floor($totalChars / $count));

        return array_fill(0, $count, $per);
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<int, int>  $widths
     */
    private function row(array $cells, array $widths): string
    {
        $out = [];

        foreach (array_values($cells) as $i => $cell) {
            $width = $widths[$i] ?? 12;
            $value = (string) $cell;

            if (mb_strlen($value) > $width - 1) {
                // Truncated for layout only. The CSV carries the untruncated
                // value, and both come from the same payload.
                $value = mb_substr($value, 0, max(1, $width - 2)).'.';
            }

            $out[] = str_pad($value, $width);
        }

        return rtrim(implode('', $out));
    }
}
