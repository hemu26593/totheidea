<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Pdf;

/**
 * A minimal, dependency-free PDF writer for text and tables.
 *
 * WHY NOT A LIBRARY. Project rule 12 says to prefer the framework's own
 * tooling and to justify every dependency. The alternative here is an
 * HTML-to-PDF converter, which brings four packages and a CSS engine in order
 * to lay out content that is entirely under our control and consists of
 * headings and tables of strings. It would also stamp a creation date into
 * every file by default, and this phase has to produce artifacts whose
 * checksum is meaningful - "retrievable byte-identical afterwards" is a stated
 * requirement, so determinism is a feature, not an accident to work around.
 *
 * WHAT THIS IS NOT: it is not a general PDF library and must not grow into
 * one. It writes PDF 1.4 with the standard Helvetica base-14 fonts, which
 * every reader has built in, so no font is embedded and no glyph metrics are
 * needed. There is no CSS, no image support and no unicode beyond WinAnsi -
 * text outside that range is transliterated rather than silently corrupting
 * the file.
 *
 * DETERMINISTIC: the same ReportData produces byte-identical output. There is
 * no creation timestamp, no producer string containing a version, and no
 * random object ids.
 */
class SimplePdfDocument
{
    /** A4 in PostScript points. */
    private const PAGE_WIDTH = 595.28;

    private const PAGE_HEIGHT = 841.89;

    private const MARGIN = 40.0;

    private const LINE_HEIGHT = 14.0;

    /** @var array<int, string> completed page content streams */
    private array $pages = [];

    /** @var array<int, string> operators for the page being written */
    private array $current = [];

    private float $cursor;

    public function __construct()
    {
        $this->cursor = self::PAGE_HEIGHT - self::MARGIN;
    }

    public function usableWidth(): float
    {
        return self::PAGE_WIDTH - (2 * self::MARGIN);
    }

    /**
     * Write one line of text, breaking to a new page when the margin is
     * reached.
     */
    public function text(string $value, float $size = 10.0, bool $bold = false, float $indent = 0.0): void
    {
        if ($this->cursor - self::LINE_HEIGHT < self::MARGIN) {
            $this->breakPage();
        }

        $this->current[] = sprintf(
            'BT /%s %s Tf %s %s Td (%s) Tj ET',
            $bold ? 'F2' : 'F1',
            $this->num($size),
            $this->num(self::MARGIN + $indent),
            $this->num($this->cursor),
            $this->escape($value),
        );

        $this->cursor -= self::LINE_HEIGHT;
    }

    /**
     * Vertical space, without drawing anything.
     */
    public function gap(float $points = 8.0): void
    {
        $this->cursor -= $points;

        if ($this->cursor < self::MARGIN) {
            $this->breakPage();
        }
    }

    /**
     * A horizontal rule across the usable width.
     */
    public function rule(): void
    {
        if ($this->cursor - 4 < self::MARGIN) {
            $this->breakPage();
        }

        $this->current[] = sprintf(
            '%s %s m %s %s l S',
            $this->num(self::MARGIN),
            $this->num($this->cursor),
            $this->num(self::PAGE_WIDTH - self::MARGIN),
            $this->num($this->cursor),
        );

        $this->cursor -= 6;
    }

    /**
     * Wrap a long string to the usable width and write it as several lines.
     */
    public function paragraph(string $value, float $size = 9.0, float $indent = 0.0): void
    {
        $charWidth = $size * 0.5; // Helvetica averages close to half the point size.
        $perLine = max(20, (int) floor(($this->usableWidth() - $indent) / $charWidth));

        foreach (explode("\n", wordwrap($value, $perLine, "\n", true)) as $line) {
            $this->text($line, $size, false, $indent);
        }
    }

    public function breakPage(): void
    {
        $this->pages[] = implode("\n", $this->current);
        $this->current = [];
        $this->cursor = self::PAGE_HEIGHT - self::MARGIN;
    }

    /**
     * The finished document.
     */
    public function render(): string
    {
        if ($this->current !== []) {
            $this->breakPage();
        }

        if ($this->pages === []) {
            $this->pages[] = '';
        }

        $pageCount = count($this->pages);

        // Object layout, fixed so ids never depend on content:
        //   1 catalog · 2 pages · 3 F1 · 4 F2 · then page/content pairs.
        $objects = [];
        $kids = [];

        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (5 + ($i * 2)).' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $kids),
            $pageCount,
        );
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $i => $stream) {
            $pageId = 5 + ($i * 2);
            $contentId = $pageId + 1;

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->num(self::PAGE_WIDTH),
                $this->num(self::PAGE_HEIGHT),
                $contentId,
            );

            $objects[$contentId] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream) + 1,
                $stream,
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $max = max(array_keys($objects));

        $pdf .= "xref\n0 ".($max + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        // No /CreationDate and no versioned /Producer: the same report data
        // must always produce the same bytes.
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $max + 1,
            $xrefOffset,
        );

        return $pdf;
    }

    /**
     * Fixed-format numbers - locale-independent, and stable across runs.
     */
    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * PDF string escaping, over WinAnsi.
     *
     * Characters outside the encoding are transliterated rather than written
     * raw, which would produce a file a reader renders as mojibake or refuses.
     */
    private function escape(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);

        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}
