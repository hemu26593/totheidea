<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Renderers;

use App\Domain\Reporting\ReportData;

/**
 * ReportData -> CSV bytes.
 *
 * THE SAME PAYLOAD THE PDF READS. No value is recomputed here: the CSV and the
 * PDF are two presentations of one authoritative structure, which is the only
 * way to guarantee that a figure cannot differ between the file somebody
 * prints and the file somebody opens in a spreadsheet.
 *
 * STABLE COLUMN ORDER, LOCALE-INDEPENDENT OUTPUT. Sections are emitted in the
 * order the builder produced them, columns in the order it declared them.
 * Values were already formatted as strings by the builder using a dot decimal
 * separator, so nothing here re-formats a number into whatever the server's
 * locale happens to be.
 *
 * A report holds several tables, and CSV has no concept of one. Each section
 * is emitted with a header line naming it, then its column row, then its
 * rows - so the file stays parseable line by line and self-describing.
 */
class CsvReportRenderer
{
    public function render(ReportData $data): string
    {
        $lines = [];

        $lines[] = $this->line(['Report', $data->title]);
        $lines[] = $this->line(['Report key', $data->reportKey]);
        $lines[] = $this->line(['Subject', class_basename($data->subject::class), (string) $data->subject->getKey()]);
        $lines[] = $this->line(['Generated at', $data->generatedAt->toIso8601String()]);

        if ($data->narrative !== null && trim($data->narrative) !== '') {
            $lines[] = $this->line(['Summary', $data->narrative]);
        }

        foreach ($data->sections as $section) {
            $lines[] = '';
            $lines[] = $this->line(['Section', $section->heading]);

            if (! $section->isEmpty()) {
                $lines[] = $this->line($section->columns);

                foreach ($section->rows as $row) {
                    $lines[] = $this->line($row);
                }
            }

            foreach ($section->notes as $note) {
                $lines[] = $this->line(['Note', $note]);
            }
        }

        // Trailing newline, LF only: CRLF would vary by platform and break the
        // byte-for-byte checksum guarantee.
        return implode("\n", $lines)."\n";
    }

    /**
     * RFC 4180 quoting, applied unconditionally to every field.
     *
     * Quoting everything costs a few bytes and removes an entire class of
     * question about which values needed it.
     *
     * @param  array<int, string>  $cells
     */
    private function line(array $cells): string
    {
        return implode(',', array_map(
            fn (string $cell): string => '"'.str_replace('"', '""', $this->flatten($cell)).'"',
            array_map('strval', array_values($cells)),
        ));
    }

    /**
     * Newlines inside a field are legal in CSV but make a file painful to
     * inspect line by line, and these reports have no multi-line values by
     * design.
     */
    private function flatten(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
