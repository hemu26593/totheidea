<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Builders\AssignmentStatusReport;
use App\Domain\Reporting\Builders\AttendanceRegisterReport;
use App\Domain\Reporting\Builders\BatchSummaryReport;
use App\Domain\Reporting\Builders\ParticipantProgressReport;
use App\Domain\Reporting\Renderers\CsvReportRenderer;
use App\Domain\Reporting\Renderers\PdfReportRenderer;
use App\Domain\Reporting\ReportArtifactService;
use App\Domain\Reporting\ReportRenderer;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\ReportArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Rendering and delivery.
 *
 * The property that matters most: the PDF and the CSV are two presentations of
 * ONE payload. Neither renderer computes anything, so a figure cannot differ
 * between the file somebody prints and the file somebody opens in a
 * spreadsheet.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private ReportArtifactService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        Storage::fake('local');
        $this->reports = app(ReportArtifactService::class);
    }

    // --- PDF -------------------------------------------------------------------

    #[Test]
    public function a_pdf_is_a_real_pdf(): void
    {
        $scenario = (new ReportScenario($this->admin()))->fullyScored();
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $pdf = app(PdfReportRenderer::class)->render($data);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('/Type /Catalog', $pdf);
        $this->assertStringContainsString('/Type /Page', $pdf);
        $this->assertStringContainsString('xref', $pdf);
        $this->assertStringContainsString('trailer', $pdf);
    }

    #[Test]
    public function a_pdf_carries_the_report_data(): void
    {
        $scenario = (new ReportScenario($this->admin()))->fullyScored();
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $pdf = app(PdfReportRenderer::class)->render($data);

        $this->assertStringContainsString('Participant Progress Report', $pdf);
        $this->assertStringContainsString($scenario->names[0], $pdf);
        $this->assertStringContainsString('Session 1', $pdf);
        // The scores ScoringService wrote, verbatim.
        $this->assertStringContainsString('7.00', $pdf);
        $this->assertStringContainsString('Delegation', $pdf);
    }

    #[Test]
    public function the_pdf_truncates_only_for_layout_and_the_csv_keeps_the_full_value(): void
    {
        // A column has a character budget, so a long title is shortened to fit
        // the page. That is presentation, and the CSV - which nobody reads in
        // fixed columns - carries the untruncated value. Both come from the
        // same payload, so this is the only difference between them.
        $scenario = (new ReportScenario($this->admin()))->full();
        $data = app(AssignmentStatusReport::class)->build($scenario->batch);

        $pdf = app(PdfReportRenderer::class)->render($data);
        $csv = app(CsvReportRenderer::class)->render($data);

        // The CSV holds the value exactly as the builder computed it.
        $this->assertStringContainsString('"Draft your Q1 time grid"', $csv);

        // The PDF holds a shortened form - how short depends on how many
        // columns share the page width, so the assertion is on the stem the
        // narrowest column keeps.
        $this->assertStringContainsString('Draft you', $pdf);
        $this->assertStringNotContainsString('Draft your Q1 time grid', $pdf);
    }

    #[Test]
    public function the_pdf_transliterates_text_outside_winansi_rather_than_corrupting_the_file(): void
    {
        // The base-14 fonts are WinAnsi. Writing a character outside that
        // encoding raw would produce a file a reader shows as mojibake or
        // refuses to open, so it is transliterated instead - and the CSV,
        // which is UTF-8, keeps the original.
        $scenario = (new ReportScenario($this->admin()))->full();
        $scenario->batch->forceFill(['name' => 'Vadodara — Q1'])->save();

        $data = app(BatchSummaryReport::class)->build($scenario->batch->fresh());

        $pdf = app(PdfReportRenderer::class)->render($data);
        $csv = app(CsvReportRenderer::class)->render($data);

        $this->assertStringContainsString('Vadodara — Q1', $csv);
        $this->assertStringContainsString('Vadodara', $pdf);
        $this->assertStringNotContainsString('—', $pdf);
    }

    #[Test]
    public function the_same_report_data_always_produces_the_same_pdf_bytes(): void
    {
        // Determinism is what makes the stored checksum mean anything. No
        // creation timestamp, no versioned producer string, no random ids.
        $scenario = (new ReportScenario($this->admin()))->fullyScored();
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $renderer = app(PdfReportRenderer::class);

        $this->assertSame(
            hash('sha256', $renderer->render($data)),
            hash('sha256', $renderer->render($data)),
        );
    }

    #[Test]
    public function a_pdf_is_not_a_screenshot_and_needs_no_font_file(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        $pdf = app(PdfReportRenderer::class)->render($data);

        // Text drawing operators, and the base-14 fonts every reader has.
        $this->assertStringContainsString('/BaseFont /Helvetica', $pdf);
        $this->assertStringContainsString(' Tj', $pdf);
        $this->assertStringNotContainsString('/Image', $pdf);
        $this->assertStringNotContainsString('/FontFile', $pdf);
    }

    // --- CSV --------------------------------------------------------------------

    #[Test]
    public function a_csv_has_stable_columns_and_rows(): void
    {
        $scenario = (new ReportScenario($this->admin(), 2))->full();
        $data = app(AttendanceRegisterReport::class)->build($scenario->batch);

        $csv = app(CsvReportRenderer::class)->render($data);
        $lines = explode("\n", trim($csv));

        $this->assertSame('"Report","Attendance Register"', $lines[0]);
        $this->assertSame('"Report key","attendance_register"', $lines[1]);
        $this->assertContains('"Section","Register"', $lines);
        $this->assertContains('"Participant","Session 1","Session 2"', $lines);
        $this->assertContains('"'.$scenario->names[0].'","present","Not marked"', $lines);
        $this->assertContains('"'.$scenario->names[1].'","absent","Not marked"', $lines);
    }

    #[Test]
    public function the_csv_and_the_pdf_carry_the_same_figures(): void
    {
        $scenario = (new ReportScenario($this->admin(), 2))->fullyScored();
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $csv = app(CsvReportRenderer::class)->render($data);
        $pdf = app(PdfReportRenderer::class)->render($data);

        $compared = 0;

        foreach ($data->sections as $section) {
            foreach ($section->rows as $row) {
                foreach ($row as $cell) {
                    $cell = (string) $cell;

                    if ($cell === '') {
                        continue;
                    }

                    // The CSV carries every computed value untouched.
                    $this->assertStringContainsString($cell, $csv);

                    // The PDF carries the same values, subject to the two
                    // presentation rules the tests above pin down: long cells
                    // are truncated to a column, and non-WinAnsi characters
                    // are transliterated. Short ASCII cells are unaffected, so
                    // they are the ones compared directly.
                    if (mb_strlen($cell) <= 12 && preg_match('/^[\x20-\x7E]+$/', $cell) === 1) {
                        $this->assertStringContainsString($cell, $pdf);
                        $compared++;
                    }
                }
            }
        }

        $this->assertGreaterThan(10, $compared, 'Too few cells were actually compared.');
    }

    #[Test]
    public function csv_numbers_are_locale_independent(): void
    {
        $scenario = (new ReportScenario($this->admin()))->fullyScored();
        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);

        $csv = app(CsvReportRenderer::class)->render($data);

        // Dot decimal separator, no thousands grouping - so the file parses
        // the same way wherever it is opened.
        $this->assertStringContainsString('"7.00"', $csv);
        $this->assertDoesNotMatchRegularExpression('/"\d+,\d{3}/', $csv);
    }

    #[Test]
    public function csv_quoting_survives_a_value_containing_a_quote(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $scenario->assignment->forceFill(['title' => 'The "big" one'])->save();

        $data = app(AssignmentStatusReport::class)->build($scenario->batch);
        $csv = app(CsvReportRenderer::class)->render($data);

        $this->assertStringContainsString('"The ""big"" one"', $csv);
    }

    #[Test]
    public function the_renderer_refuses_an_unknown_format(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        $this->expectException(InvalidArgumentException::class);

        app(ReportRenderer::class)->render($data, 'xlsx');
    }

    // --- Artifacts ---------------------------------------------------------------

    #[Test]
    public function exporting_stores_the_file_and_records_what_was_delivered(): void
    {
        $scenario = (new ReportScenario($this->admin()))->fullyScored();
        $actor = $this->admin();

        $artifact = $this->reports->export(
            'participant_progress',
            $scenario->enrollments[0],
            $actor,
            ReportArtifact::FORMAT_PDF,
            ['note' => 'for session 1'],
        );

        $this->assertSame('participant_progress', $artifact->report_key);
        $this->assertSame(ReportArtifact::FORMAT_PDF, $artifact->format);
        $this->assertSame($scenario->enrollments[0]->getMorphClass(), $artifact->subject_type);
        $this->assertSame($scenario->enrollments[0]->getKey(), (int) $artifact->subject_id);
        $this->assertSame(['note' => 'for session 1'], $artifact->parameters);
        $this->assertSame($actor->getKey(), $artifact->generated_by);

        Storage::disk('local')->assertExists($artifact->path);
    }

    #[Test]
    public function the_checksum_matches_the_delivered_file(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $artifact = $this->reports->export('batch_summary', $scenario->batch, $this->admin());

        $bytes = (string) Storage::disk($artifact->disk)->get($artifact->path);

        $this->assertSame(hash('sha256', $bytes), $artifact->checksum_sha256);
        $this->assertSame(64, strlen($artifact->checksum_sha256));
    }

    #[Test]
    public function retrieving_an_artifact_verifies_it_is_the_file_that_was_issued(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $artifact = $this->reports->export('batch_summary', $scenario->batch, $this->admin());

        $bytes = $this->reports->contents($artifact, $this->admin());
        $this->assertStringStartsWith('%PDF-1.4', $bytes);

        // Tamper with the stored file: handing it over would be worse than
        // refusing, because the record says something else was delivered.
        Storage::disk($artifact->disk)->put($artifact->path, 'not the file that was issued');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its recorded checksum');

        $this->reports->contents($artifact->fresh(), $this->admin());
    }

    #[Test]
    public function regenerating_produces_a_new_artifact_and_leaves_the_first_untouched(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $admin = $this->admin();

        $first = $this->reports->export('batch_summary', $scenario->batch, $admin);
        $firstChecksum = $first->checksum_sha256;
        $firstPath = $first->path;

        // The data underneath moves on.
        $scenario->withActionItems();

        $second = $this->reports->export('batch_summary', $scenario->batch, $admin);

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame($firstChecksum, $first->fresh()->checksum_sha256);
        $this->assertSame($firstPath, $first->fresh()->path);
        Storage::disk('local')->assertExists($firstPath);
        $this->assertSame(2, ReportArtifact::query()->count());
    }

    #[Test]
    public function an_artifact_is_immutable(): void
    {
        $artifact = ReportArtifact::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $artifact->forceFill(['checksum_sha256' => str_repeat('0', 64)])->save();
    }

    #[Test]
    public function an_artifact_cannot_be_deleted(): void
    {
        $artifact = ReportArtifact::factory()->create();

        $this->expectException(RuntimeException::class);
        $artifact->delete();
    }

    #[Test]
    public function not_even_a_super_admin_can_rewrite_an_artifact(): void
    {
        // 'update' is not a guarded ability, so Gate::before grants it and the
        // policy is never consulted. The guard is on the model for that reason.
        $artifact = ReportArtifact::factory()->create();
        $superAdmin = $this->superAdmin();

        $this->assertTrue($superAdmin->can('update', $artifact));
        $this->actingAs($superAdmin);

        $this->expectException(RuntimeException::class);
        $artifact->forceFill(['path' => 'somewhere/else.pdf'])->save();
    }

    #[Test]
    public function the_history_lists_every_report_issued_about_a_subject(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $admin = $this->admin();

        $this->reports->export('batch_summary', $scenario->batch, $admin);
        $this->reports->export('attendance_register', $scenario->batch, $admin, ReportArtifact::FORMAT_CSV);

        $history = $this->reports->historyFor($scenario->batch);

        $this->assertCount(2, $history);
        $this->assertSame(['attendance_register', 'batch_summary'], $history->pluck('report_key')->sort()->values()->all());
    }

    #[Test]
    public function an_artifact_is_never_read_back_as_a_data_source(): void
    {
        // Reports are queries over operational data. Nothing in the reporting
        // domain reads a figure out of a stored file - the only method that
        // touches the bytes hands them over whole.
        foreach (glob(app_path('Domain/Reporting/**/*.php')) + glob(app_path('Domain/Reporting/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            foreach (['str_getcsv', 'fgetcsv', 'parseCsv', 'preg_match(', 'json_decode'] as $reading) {
                if (str_contains($source, $reading) && str_contains($source, 'Storage::disk')) {
                    $this->fail(basename($file).' appears to parse a stored artifact.');
                }
            }
        }

        $this->assertTrue(true);
    }

    // --- Audit -------------------------------------------------------------------

    #[Test]
    public function generating_and_exporting_are_audited(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $admin = $this->admin();

        $this->reports->generate('batch_summary', $scenario->batch, $admin);
        $this->reports->export('batch_summary', $scenario->batch, $admin);

        $generated = AuditLog::query()->where('action', AuditAction::ReportGenerated)->latest('id')->first();
        $exported = AuditLog::query()->where('action', AuditAction::ReportExported)->latest('id')->first();

        $this->assertNotNull($generated);
        $this->assertSame('batch_summary', $generated->new_values['report_key']);
        $this->assertNotNull($exported);
        $this->assertSame('pdf', $exported->new_values['format']);
        $this->assertSame($admin->getKey(), $exported->actor_id);
    }

    #[Test]
    public function no_new_audit_action_was_invented(): void
    {
        // report.generated and report.exported already existed from Phase 0.
        $this->assertSame('report.generated', AuditAction::ReportGenerated->value);
        $this->assertSame('report.exported', AuditAction::ReportExported->value);
    }

    // --- Reports are not stored on a public disk --------------------------------------

    #[Test]
    public function artifacts_land_on_a_private_disk(): void
    {
        // A report aggregates a whole business's position. On the 'public'
        // disk every artifact would be reachable by URL to anyone who guessed
        // a path.
        $this->assertSame('local', config('reports.disk'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
    }
}
