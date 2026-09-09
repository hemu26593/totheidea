<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Domain\Ai\AiApprovalService;
use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\PromptVersionService;
use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Customers\CustomerService;
use App\Domain\Reporting\Renderers\CsvReportRenderer;
use App\Domain\Reporting\ReportArtifactService;
use App\Domain\Reporting\ReportRegistry;
use App\Domain\Sessions\AttendanceService;
use App\Domain\Sessions\SessionSchedulingService;
use App\Enums\AiGenerationStatus;
use App\Livewire\Ai\Generations;
use App\Livewire\Ai\ShowGeneration;
use App\Livewire\Customers\Ai as CustomerAi;
use App\Livewire\Dashboard\Index as Dashboard;
use App\Livewire\Reports\Index as ReportsScreen;
use App\Models\AccessGrant;
use App\Models\AiGeneration;
use App\Models\AssignmentInstance;
use App\Models\AssignmentTemplate;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\Program;
use App\Models\QuestionOption;
use App\Models\ReportArtifact;
use App\Models\SessionAttendance;
use App\Models\SessionTemplate;
use App\Models\SubmissionScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ai\AiScenario;
use Tests\TestCase;

/**
 * UAT: the outputs - what leaves the building, and what the model proposes.
 */
class ReportingAndAiUatTest extends TestCase
{
    use RefreshDatabase;

    private Customer $alpha;

    private Enrollment $enrollment;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        $program = Program::factory()->create(['session_count' => 6]);
        $this->batch = Batch::factory()->create(['program_id' => $program->getKey()]);

        $this->alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $this->enrollment = Enrollment::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'batch_id' => $this->batch->getKey(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function all_four_implemented_reports_generate_in_both_formats(): void
    {
        $admin = $this->admin();
        $reports = app(ReportArtifactService::class);

        $subjects = [
            'participant_progress' => $this->enrollment,
            'batch_summary' => $this->batch,
            'attendance_register' => $this->batch,
            'assignment_status' => $this->batch,
        ];

        $this->assertSame(array_keys($subjects), app(ReportRegistry::class)->keys());

        foreach ($subjects as $key => $subject) {
            foreach (['pdf', 'csv'] as $format) {
                $artifact = $reports->export($key, $subject, $admin, $format);

                $this->assertSame($key, $artifact->report_key);
                $this->assertSame($format, $artifact->format);
                $this->assertSame(64, strlen($artifact->checksum_sha256));

                // The stored bytes are the bytes that were issued.
                $bytes = Storage::disk($artifact->disk)->get($artifact->path);
                $this->assertSame($artifact->checksum_sha256, hash('sha256', $bytes));

                $format === 'pdf'
                    ? $this->assertStringStartsWith('%PDF-', $bytes)
                    : $this->assertStringContainsString(',', $bytes);
            }
        }

        $this->assertSame(8, ReportArtifact::query()->count());
    }

    #[Test]
    public function the_diagnostic_report_stays_unavailable_because_no_builder_exists(): void
    {
        // KNOWN: `diagnostic` is part of the report_artifacts vocabulary and
        // has no builder. It is not offered, and asking for it fails loudly
        // rather than producing an empty document.
        $this->assertContains('diagnostic', ReportArtifact::REPORT_KEYS);
        $this->assertNotContains('diagnostic', app(ReportRegistry::class)->keys());

        Livewire::actingAs($this->admin())
            ->test(ReportsScreen::class)
            ->assertViewHas('reportKeys', fn (array $keys) => ! in_array('diagnostic', $keys, true));

        $this->expectException(\InvalidArgumentException::class);
        app(ReportArtifactService::class)->export('diagnostic', $this->enrollment, $this->admin());
    }

    #[Test]
    public function report_figures_match_the_underlying_records(): void
    {
        $admin = $this->admin();

        // Four sessions held, with a known register.
        $marks = [
            SessionAttendance::STATUS_PRESENT,
            SessionAttendance::STATUS_PRESENT,
            SessionAttendance::STATUS_ABSENT,
            SessionAttendance::STATUS_LATE,
        ];

        foreach ($marks as $index => $status) {
            $template = SessionTemplate::factory()->create([
                'program_id' => $this->batch->program_id,
                'sequence' => $index + 1,
            ]);

            $session = app(SessionSchedulingService::class)
                ->schedule($this->batch, $template, now()->subWeeks(4 - $index)->toDateString(), $admin);
            $session = app(SessionSchedulingService::class)
                ->begin($session, now()->subWeeks(4 - $index)->toDateString(), $admin);

            app(AttendanceService::class)
                ->mark($session, $this->enrollment, $status, $admin);
        }

        // The database says: 4 marks, 2 present, 1 absent, 1 late.
        $fromDb = SessionAttendance::query()
            ->where('enrollment_id', $this->enrollment->getKey())
            ->get()
            ->groupBy('status')
            ->map->count();

        $this->assertSame(2, $fromDb['present']);
        $this->assertSame(1, $fromDb['absent']);
        $this->assertSame(1, $fromDb['late']);

        // The CSV must say exactly the same thing.
        $data = app(ReportArtifactService::class)
            ->generate('attendance_register', $this->batch, $admin);

        $csv = app(CsvReportRenderer::class)->render($data);

        // The register section carries one cell per mark, verbatim. Counting the
        // word in the whole file would also catch the `Present` column heading of
        // the marks-by-status section, so count the cells the register actually
        // emitted for this participant instead.
        $register = collect($data->sections)->firstWhere('heading', 'Register');
        $this->assertNotNull($register);

        $cells = collect($register->rows)
            ->flatMap(fn (array $row): array => array_slice($row, 1))
            ->countBy();

        $this->assertSame(2, $cells['present'], 'The register reports the marks that exist, not a modelled figure.');
        $this->assertSame(1, $cells['absent']);
        $this->assertSame(1, $cells['late']);

        // The marks-by-status section repeats the same counts and invents nothing.
        $marks = collect($data->sections)->firstWhere('heading', 'Marks by status');
        $this->assertNotNull($marks);
        $this->assertSame(['Participant', 'Present', 'Absent', 'Late', 'Excused', 'Marked'], $marks->columns);
        $this->assertSame(['Alpha Metalworks', '2', '1', '1', '0', '4'], $marks->rows[0]);

        // No attendance percentage is delivered. The only `%` in the file is inside
        // the notes that name the 90% rule as an unresolved client decision - the
        // completion section carries prose and no row at all.
        $completion = collect($data->sections)->firstWhere('heading', 'Completion against the 90% rule');
        $this->assertNotNull($completion);
        $this->assertTrue($completion->isEmpty());
        $this->assertStringContainsString('CLIENT DECISION - ATTENDANCE WEIGHTING', implode(' ', $completion->notes));

        $everyCell = collect($data->sections)
            ->flatMap(fn ($section): array => $section->rows)
            ->flatten();

        foreach ($everyCell as $cell) {
            $this->assertStringNotContainsString('%', (string) $cell, 'No delivered figure is a percentage.');
        }

        $this->assertStringContainsString('Attendance Register', $csv);
    }

    #[Test]
    public function a_report_is_scoped_to_its_subject_and_not_to_the_whole_database(): void
    {
        $admin = $this->admin();

        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);
        Enrollment::factory()->create([
            'customer_id' => $beta->getKey(),
            'batch_id' => Batch::factory()->create(['program_id' => $this->batch->program_id])->getKey(),
        ]);

        $data = app(ReportArtifactService::class)
            ->generate('participant_progress', $this->enrollment, $admin);

        $rendered = app(CsvReportRenderer::class)->render($data);

        $this->assertStringContainsString('Alpha Metalworks', $rendered);
        $this->assertStringNotContainsString('Beta Textiles', $rendered);
        $this->assertSame((int) $this->alpha->getKey(), $data->customerId);
    }

    #[Test]
    public function staff_may_preview_a_report_but_not_export_one(): void
    {
        $staff = $this->staff();

        // reports.view - the figures on screen.
        $this->assertTrue($staff->can('reports.view'));
        app(ReportArtifactService::class)->generate('participant_progress', $this->enrollment, $staff);

        // reports.export - a file that leaves the system.
        $this->assertFalse($staff->can('reports.export'));

        // The refusal is the domain's own, raised inside ReportArtifactService rather
        // than by a policy: exporting is guarded by the service that writes the file.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reports.export is required');
        app(ReportArtifactService::class)->export('participant_progress', $this->enrollment, $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_ai_lifecycle_runs_generate_validate_draft_approve_publish(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();

        $this->publishFormDraftPrompt($generator);
        AiScenario::bindProvider(AiScenario::validFormProposal());

        // GENERATE
        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $this->alpha])
            ->set('brief', 'A short operations intake covering staffing and daily routine.')
            ->call('generate')
            ->assertHasNoErrors();

        $generation = AiGeneration::query()->firstOrFail();

        // VALIDATE - provenance is complete and the output conformed.
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->status);
        $this->assertSame((int) $this->alpha->getKey(), (int) $generation->customer_id);
        $this->assertSame((int) $generator->getKey(), (int) $generation->generated_by);
        $this->assertNotNull($generation->generated_at);
        $this->assertNotNull($generation->promptVersion);
        $this->assertIsArray($generation->validated_output);
        $this->assertIsArray($generation->input_context);

        // Generating publishes nothing.
        $this->assertSame(0, FormVersion::query()->where('status', 'published')->count());

        // DRAFT
        $template = FormTemplate::factory()->create();

        Livewire::actingAs($generator)
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->set('draftTemplateId', $template->getKey())
            ->call('draft')
            ->assertHasNoErrors();

        $generation->refresh();
        $this->assertSame(AiGenerationStatus::AwaitingApproval, $generation->status);
        $this->assertTrue($generation->resultingFormVersion->isDraft());

        // PREVIEW + self-approval refusal.
        Livewire::actingAs($generator)
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->assertViewHas('viewerIsGenerator', true)
            ->assertViewHas('canDecide', false)
            ->call('approve')
            ->assertForbidden();

        $this->assertDatabaseCount('ai_approvals', 0);

        // APPROVE by a second person -> PUBLISH.
        Livewire::actingAs($approver)
            ->test(ShowGeneration::class, ['generation' => $generation])
            ->assertViewHas('canDecide', true)
            ->set('remark', 'Reads well.')
            ->call('approve')
            ->assertHasNoErrors();

        $generation->refresh();
        $this->assertSame(AiGenerationStatus::Approved, $generation->status);
        $this->assertTrue($generation->resultingFormVersion->isPublished());
        $this->assertSame((int) $approver->getKey(), (int) $generation->approval->decided_by);
    }

    #[Test]
    public function a_failed_generation_is_kept_and_produces_no_draft(): void
    {
        $generator = $this->admin();
        $this->publishFormDraftPrompt($generator);

        AiScenario::bindProvider('Here is the form you asked for!');

        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $this->alpha])
            ->set('brief', 'Something the model will fumble.')
            ->call('generate');

        $generation = AiGeneration::query()->firstOrFail();

        $this->assertSame(AiGenerationStatus::Failed, $generation->status);
        $this->assertNull($generation->validated_output);
        $this->assertNotNull($generation->raw_output, 'The raw answer is kept so the prompt can be fixed.');
        $this->assertNotNull($generation->validation_error);
        $this->assertNull($generation->resulting_form_version_id);

        // It is listed rather than hidden - a failure is provenance too.
        Livewire::actingAs($generator)
            ->test(Generations::class)
            ->assertSee('Failed');
    }

    #[Test]
    public function ai_writes_no_figure_no_permission_and_no_user(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();

        $usersBefore = User::query()->count();

        $this->publishFormDraftPrompt($generator);
        AiScenario::bindProvider(AiScenario::validFormProposal());

        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $this->alpha])
            ->set('brief', 'A short intake.')
            ->call('generate');

        $generation = AiGeneration::query()->firstOrFail();
        app(AiFormDraftService::class)->draftFrom($generation, FormTemplate::factory()->create());
        app(AiApprovalService::class)->approve($generation->fresh(), $approver);

        // Nothing authoritative moved.
        $this->assertSame(0, SubmissionScore::query()->count());
        $this->assertSame(0, SessionAttendance::query()->count());
        $this->assertSame(0, MmdEntry::query()->count());
        $this->assertSame(0, MmdTarget::query()->count());
        $this->assertSame(0, AccessGrant::query()->count());
        $this->assertSame($usersBefore, User::query()->count());

        // And no option gained a numeric score on the way through.
        $this->assertSame(0, QuestionOption::query()->whereNotNull('score_value')->count());
    }

    #[Test]
    public function an_ai_generation_is_bound_to_one_customer_and_cannot_reach_another(): void
    {
        $generator = $this->admin();
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        $this->publishFormDraftPrompt($generator);
        $double = AiScenario::bindProvider(AiScenario::validFormProposal());

        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $this->alpha])
            ->set('brief', 'A short intake.')
            ->call('generate');

        $payload = $double->lastPayload();

        $this->assertStringContainsString('Alpha Metalworks', $payload);
        $this->assertStringNotContainsString('Beta Textiles', $payload);

        // Beta's AI tab shows nothing of Alpha's.
        Livewire::actingAs($generator)
            ->test(CustomerAi::class, ['customer' => $beta])
            ->assertViewHas('generations', fn ($generations) => $generations->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard consistency
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_dashboard_figure_matches_the_records_behind_it(): void
    {
        $admin = $this->admin();

        // Known state.
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);
        Enrollment::factory()->create([
            'customer_id' => $beta->getKey(),
            'batch_id' => $this->batch->getKey(),
        ]);

        // One archived customer - excluded from the count.
        app(CustomerService::class)
            ->archive(Customer::factory()->create(), $admin);

        // One overdue assignment.
        $template = SessionTemplate::factory()->create(['program_id' => $this->batch->program_id, 'sequence' => 1]);
        $session = app(SessionSchedulingService::class)
            ->schedule($this->batch, $template, now()->subWeeks(2)->toDateString(), $admin);
        $session = app(SessionSchedulingService::class)->begin($session, now()->subWeeks(2)->toDateString(), $admin);

        $assignmentTemplate = AssignmentTemplate::factory()->create([
            'session_template_id' => $template->getKey(),
        ]);
        $releases = app(AssignmentReleaseService::class);
        $instance = $releases->stageFromTemplate($session, $assignmentTemplate, now()->subDays(4)->toDateString(), $admin);
        $releases->release($instance, $admin);

        // One recorded absence.
        app(AttendanceService::class)
            ->mark($session->fresh(), $this->enrollment, SessionAttendance::STATUS_ABSENT, $admin);

        $metrics = collect(
            Livewire::actingAs($admin)->test(Dashboard::class)->viewData('metrics')
        )->keyBy('label');

        // Each figure, checked against the query it claims to summarise.
        $this->assertSame(
            Customer::query()->whereNull('archived_at')->count(),
            $metrics['Customers']['value'],
        );
        $this->assertSame(2, $metrics['Customers']['value'], 'The archived customer is excluded.');

        $this->assertSame(
            Enrollment::query()->where('status', 'enrolled')->count(),
            $metrics['Active enrolments']['value'],
        );

        $this->assertSame(
            AssignmentInstance::query()
                ->where('status', AssignmentInstance::STATUS_RELEASED)
                ->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            $metrics['Overdue assignments']['value'],
        );
        $this->assertSame(1, $metrics['Overdue assignments']['value']);

        $this->assertSame(
            SessionAttendance::query()->where('status', SessionAttendance::STATUS_ABSENT)->count(),
            $metrics['Absences recorded']['value'],
        );
        $this->assertSame(1, $metrics['Absences recorded']['value']);
    }

    #[Test]
    public function the_dashboard_shows_no_figure_whose_rule_is_undecided(): void
    {
        $rendered = Livewire::actingAs($this->admin())->test(Dashboard::class);

        $rendered->assertSee('Attendance percentage and completion scores are deliberately absent');

        foreach (['Attendance rate', 'Completion score', 'Progress %', '90%'] as $forbidden) {
            $rendered->assertDontSee($forbidden);
        }

        // The absence metric is labelled as a count, so nobody reads it as a rate.
        $metrics = collect(Livewire::actingAs($this->admin())->test(Dashboard::class)->viewData('metrics'))
            ->keyBy('label');

        $this->assertSame('Count, not a percentage', $metrics['Absences recorded']['hint']);
    }

    private function publishFormDraftPrompt(User $author): void
    {
        $prompts = app(PromptVersionService::class);

        $prompts->publish(
            $prompts->createDraft(
                key: 'form_draft',
                template: 'Propose an intake form for {{ business_name }}.',
                author: $author,
                outputSchema: AiFormDraftService::outputSchema(),
            ),
            $author,
        );
    }
}
