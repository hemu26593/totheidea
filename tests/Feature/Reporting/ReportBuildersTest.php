<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Builders\AssignmentStatusReport;
use App\Domain\Reporting\Builders\AttendanceRegisterReport;
use App\Domain\Reporting\Builders\BatchSummaryReport;
use App\Domain\Reporting\Builders\ParticipantProgressReport;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportScope;
use App\Domain\Reporting\ReportSection;
use App\Domain\Scoring\ScoringService;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\MmdTargetService;
use App\Exceptions\CustomerIsolationException;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\FormSubmission;
use App\Models\MmdTarget;
use App\Models\SessionAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four report builders.
 *
 * Every assertion here is about a figure that came from operational data. The
 * negative assertions matter just as much: no band, no invented percentage, no
 * KPI nobody defined.
 */
class ReportBuildersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    /**
     * Find a section by heading, so a test reads by name rather than index.
     */
    private function section(ReportData $data, string $heading): ReportSection
    {
        foreach ($data->sections as $section) {
            if ($section->heading === $heading) {
                return $section;
            }
        }

        $this->fail("No section [{$heading}] in report [{$data->reportKey}].");
    }

    private function notes(ReportData $data): string
    {
        $notes = [];

        foreach ($data->sections as $section) {
            $notes = array_merge($notes, $section->notes);
        }

        return implode(' ', $notes);
    }

    // --- Participant progress ---------------------------------------------------

    #[Test]
    public function the_participant_report_is_about_one_enrolment(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $enrollment = $scenario->enrollments[0];

        $data = app(ParticipantProgressReport::class)->build($enrollment);

        $this->assertSame('participant_progress', $data->reportKey);
        $this->assertSame($enrollment->getKey(), $data->subject->getKey());
        $this->assertSame((int) $enrollment->customer_id, $data->customerId);
        $this->assertSame($scenario->names[0], $this->section($data, 'Participant')->rows[0][1]);
    }

    #[Test]
    public function the_participant_report_reads_sessions_and_the_recorded_mark(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $sessions = $this->section($data, 'Sessions');

        $this->assertCount(2, $sessions->rows);
        $this->assertSame('Session 1', $sessions->rows[0][0]);
        // The mark verbatim - not weighted, not scored.
        $this->assertSame(SessionAttendance::STATUS_PRESENT, $sessions->rows[0][4]);
        $this->assertSame('Not marked', $sessions->rows[1][4]);
    }

    #[Test]
    public function the_participant_report_reads_the_assignment_lifecycle(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $assignments = $this->section($data, 'Assignments');

        $this->assertCount(1, $assignments->rows);
        $this->assertSame('Draft your Q1 time grid', $assignments->rows[0][0]);
        // Returned, one attempt so far, one decision recorded.
        $this->assertSame('returned', $assignments->rows[0][1]);
        $this->assertSame('1', $assignments->rows[0][2]);
        $this->assertSame('1', $assignments->rows[0][4]);
    }

    #[Test]
    public function the_participant_report_counts_action_items_by_status(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $actions = $this->section($data, 'Action plan');

        $byStatus = collect($actions->rows)->keyBy(0)->map(fn (array $r): string => $r[1]);

        $this->assertSame('1', $byStatus['open']);
        $this->assertSame('1', $byStatus['done']);
        $this->assertSame('0', $byStatus['dropped']);
    }

    #[Test]
    public function the_participant_report_invents_no_band_or_grade(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $payload = json_encode($data->toArray());

        // No Average / Good / Better / Best mapping, no heat-map band, no
        // weak-skill classification anywhere in the computed payload.
        foreach (['Average', 'Better', 'Best', 'band', 'weak', 'grade'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                (string) $payload,
                "The report must not introduce [{$forbidden}] - the thresholds are deferred (L2)."
            );
        }
    }

    #[Test]
    public function the_participant_report_reads_the_scores_scoring_service_wrote(): void
    {
        $scenario = (new ReportScenario($this->admin()))->fullyScored();

        $data = app(ParticipantProgressReport::class)->build($scenario->enrollments[0]);
        $scores = $this->section($data, 'Scores');

        // 7 out of a possible 10, exactly as ScoringService computed it, for
        // both the overall score and the skill area.
        $byType = collect($scores->rows)->keyBy(1);

        $this->assertSame('7.00', $byType['overall'][3]);
        $this->assertSame('10.00', $byType['overall'][4]);
        $this->assertSame('Delegation', $byType['skill_area'][2]);
        $this->assertSame('7.00', $byType['skill_area'][3]);
        $this->assertSame(ScoringService::SCHEME_VERSION, $byType['overall'][6]);

        // And the report says plainly that it applied no banding.
        $this->assertStringContainsString('L2', $this->notes($data));
    }

    #[Test]
    public function the_participant_report_shows_the_newest_score_per_type(): void
    {
        $scenario = (new ReportScenario($this->admin()))->fullyScored();
        $enrollment = $scenario->enrollments[0];

        $submission = FormSubmission::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        // submission_scores is append-only: rescoring adds rows.
        app(ScoringService::class)->score($submission);

        $data = app(ParticipantProgressReport::class)->build($enrollment);
        $scores = $this->section($data, 'Scores');

        // One current row per (submission, type, area) - a selection of the
        // newest, never an average or a sum across recomputations.
        $this->assertCount(2, $scores->rows);
    }

    #[Test]
    public function the_participant_report_aggregates_mmd_without_assuming_a_grain(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $enrollment = $scenario->enrollments[0];
        $customer = $enrollment->customer()->first();

        // Two rows on one day: normal under B1 answer B, a duplicate under
        // answer A. The report must total them either way.
        app(MmdEntryService::class)->record($customer, '2026-10-05', ['production' => 100], $enrollment);
        app(MmdEntryService::class)->record($customer, '2026-10-06', ['production' => 250], $enrollment);

        app(MmdTargetService::class)->set(
            $enrollment, 'production', MmdTarget::PERIOD_MONTHLY,
            '2026-10-01', '2026-10-31', 500, $this->admin(),
        );

        $data = app(ParticipantProgressReport::class)->build($enrollment->fresh());
        $mmd = $this->section($data, 'MMD targets');

        $this->assertCount(1, $mmd->rows);
        $this->assertSame('500.00', $mmd->rows[0][3]);
        $this->assertSame('350.00', $mmd->rows[0][4]);
        $this->assertSame('-150.00', $mmd->rows[0][5]);
    }

    // --- Batch summary -----------------------------------------------------------

    #[Test]
    public function the_batch_summary_counts_participants_and_sessions(): void
    {
        $scenario = (new ReportScenario($this->admin(), 3))->full();

        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        $batch = collect($this->section($data, 'Batch')->rows)->keyBy(0)->map(fn (array $r): string => $r[1]);
        $this->assertSame('3', $batch['Participants']);

        $sessions = collect($this->section($data, 'Sessions')->rows)->keyBy(0)->map(fn (array $r): string => $r[1]);
        $this->assertSame('1', $sessions['completed']);
        $this->assertSame('1', $sessions['scheduled']);
    }

    #[Test]
    public function the_batch_summary_counts_attendance_marks_without_weighting_them(): void
    {
        $scenario = (new ReportScenario($this->admin(), 3))->full();

        $data = app(BatchSummaryReport::class)->build($scenario->batch);
        $attendance = collect($this->section($data, 'Attendance')->rows)->keyBy(0)->map(fn (array $r): string => $r[1]);

        $this->assertSame('1', $attendance['present']);
        $this->assertSame('2', $attendance['absent']);
        $this->assertSame('3', $attendance['Total marks']);
    }

    #[Test]
    public function the_batch_summary_shows_no_completion_percentage(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        $this->assertStringContainsString('ATTENDANCE WEIGHTING', $this->notes($data));
        $this->assertStringNotContainsString('%', json_encode($this->section($data, 'Attendance')->rows) ?: '');
    }

    #[Test]
    public function the_batch_summary_aggregates_no_mmd(): void
    {
        // A batch spans several businesses. A batch-level Fund IN would sum
        // unrelated companies' finances into one meaningless, revealing
        // figure.
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        foreach ($data->sections as $section) {
            $this->assertStringNotContainsStringIgnoringCase('mmd', $section->heading);
            $this->assertStringNotContainsStringIgnoringCase('fund_in', json_encode($section->rows) ?: '');
        }
    }

    // --- Attendance register --------------------------------------------------------

    #[Test]
    public function the_register_has_a_row_per_participant_and_a_column_per_session(): void
    {
        $scenario = (new ReportScenario($this->admin(), 3))->full();

        $data = app(AttendanceRegisterReport::class)->build($scenario->batch);
        $register = $this->section($data, 'Register');

        $this->assertSame(['Participant', 'Session 1', 'Session 2'], $register->columns);
        $this->assertCount(3, $register->rows);
        $this->assertSame([$scenario->names[0], 'present', 'Not marked'], $register->rows[0]);
        $this->assertSame([$scenario->names[1], 'absent', 'Not marked'], $register->rows[1]);
    }

    #[Test]
    public function the_register_counts_marks_by_status(): void
    {
        $scenario = (new ReportScenario($this->admin(), 2))->full();

        $data = app(AttendanceRegisterReport::class)->build($scenario->batch);
        $counts = $this->section($data, 'Marks by status');

        $this->assertSame(['Participant', 'Present', 'Absent', 'Late', 'Excused', 'Marked'], $counts->columns);
        $this->assertSame([$scenario->names[0], '1', '0', '0', '0', '1'], $counts->rows[0]);
        $this->assertSame([$scenario->names[1], '0', '1', '0', '0', '1'], $counts->rows[1]);
    }

    #[Test]
    public function the_register_refuses_to_invent_a_completion_percentage(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $this->assertFalse(app()->bound(AttendanceWeighting::class));

        $data = app(AttendanceRegisterReport::class)->build($scenario->batch);
        $completion = $this->section($data, 'Completion against the 90% rule');

        // No rows at all - a statement of the dependency, by name.
        $this->assertSame([], $completion->rows);
        $this->assertStringContainsString('[CLIENT DECISION - ATTENDANCE WEIGHTING]', implode(' ', $completion->notes));
        $this->assertStringContainsString('late', implode(' ', $completion->notes));
        $this->assertStringContainsString('excused', implode(' ', $completion->notes));
    }

    #[Test]
    public function the_register_never_prints_a_number_derived_from_an_assumed_rule(): void
    {
        $scenario = (new ReportScenario($this->admin(), 2))->full();

        $data = app(AttendanceRegisterReport::class)->build($scenario->batch);
        $completion = $this->section($data, 'Completion against the 90% rule');

        // Nothing that could be read as a percentage or a yes/no reachability
        // verdict.
        $flat = json_encode([$completion->columns, $completion->rows]) ?: '';
        $this->assertStringNotContainsString('Current %', $flat);
        $this->assertStringNotContainsString('Can still reach', $flat);
    }

    #[Test]
    public function the_register_produces_both_figures_the_moment_a_weighting_is_bound(): void
    {
        // ---------------------------------------------------------------
        // WIRING PROBE, NOT A PROPOSED RULE. This stub answers the two open
        // questions arbitrarily so the seam either side of them can be
        // exercised. It exists only in this method, is never bound in
        // application code or config, and must not be read as a suggestion
        // about how `late` or `excused` should count.
        // ---------------------------------------------------------------
        $this->app->bind(AttendanceWeighting::class, fn (): AttendanceWeighting => new class implements AttendanceWeighting
        {
            public function attendanceCredit(string $status): float
            {
                return $status === SessionAttendance::STATUS_PRESENT ? 1.0 : 0.0;
            }

            public function countsTowardTotal(string $status): bool
            {
                return true;
            }
        });

        $scenario = (new ReportScenario($this->admin(), 2))->full();

        $data = app(AttendanceRegisterReport::class)->build($scenario->batch);
        $completion = $this->section($data, 'Completion against the 90% rule');

        $this->assertSame(['Participant', 'Current %', 'Can still reach 90%'], $completion->columns);
        $this->assertCount(2, $completion->rows);
        $this->assertSame('100.00', $completion->rows[0][1]);
        $this->assertSame('0.00', $completion->rows[1][1]);
    }

    // --- Assignment status ------------------------------------------------------------

    #[Test]
    public function the_assignment_report_reads_the_real_lifecycle(): void
    {
        $scenario = (new ReportScenario($this->admin(), 3))->full();

        $data = app(AssignmentStatusReport::class)->build($scenario->batch);
        $assignments = $this->section($data, 'Assignments');

        $row = collect($assignments->columns)->combine($assignments->rows[0]);

        $this->assertSame('Draft your Q1 time grid', $row['Assignment']);
        $this->assertSame('released', $row['Assignment status']);
        $this->assertSame('3', $row['Participants']);
        $this->assertSame('1', $row['Returned']);
        // Two participants never engaged - an absence, not a status.
        $this->assertSame('2', $row['No submission row']);
    }

    #[Test]
    public function the_assignment_report_lists_every_decision_not_just_the_latest(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(AssignmentStatusReport::class)->build($scenario->batch);
        $reviews = $this->section($data, 'Review history');

        $this->assertCount(1, $reviews->rows);
        $this->assertSame('returned', $reviews->rows[0][3]);
        $this->assertSame('Needs the numbers.', $reviews->rows[0][5]);
    }

    #[Test]
    public function the_assignment_report_invents_no_status(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $data = app(AssignmentStatusReport::class)->build($scenario->batch);
        $columns = $this->section($data, 'Assignments')->columns;

        // Every status column is one the assignment domain defines.
        $statusColumns = array_slice($columns, 4, count(AssignmentSubmission::STATUSES));
        $this->assertSame(
            array_map('ucfirst', AssignmentSubmission::STATUSES),
            $statusColumns,
        );
    }

    // --- Subject type is checked ---------------------------------------------------------

    #[Test]
    public function a_participant_report_cannot_be_pointed_at_a_batch(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $this->expectException(InvalidArgumentException::class);

        app(ParticipantProgressReport::class)->build($scenario->batch);
    }

    #[Test]
    public function a_batch_report_cannot_be_pointed_at_an_enrolment(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        $this->expectException(InvalidArgumentException::class);

        app(BatchSummaryReport::class)->build($scenario->enrollments[0]);
    }

    #[Test]
    public function a_batch_report_declares_that_it_has_no_single_owning_business(): void
    {
        $scenario = (new ReportScenario($this->admin(), 2))->full();

        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        // Naming one customer here would be a lie about scope.
        $this->assertSame(ReportScope::NO_SINGLE_CUSTOMER, $data->customerId);
    }

    #[Test]
    public function an_unsupported_subject_fails_closed(): void
    {
        $this->expectException(CustomerIsolationException::class);

        app(ReportScope::class)->customerIdFor(Customer::factory()->create());
    }

    // --- The Phase 9 boundary ----------------------------------------------------------------

    #[Test]
    public function every_report_renders_with_no_narrative(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();

        foreach ([
            [ParticipantProgressReport::class, $scenario->enrollments[0]],
            [BatchSummaryReport::class, $scenario->batch],
            [AttendanceRegisterReport::class, $scenario->batch],
            [AssignmentStatusReport::class, $scenario->batch],
        ] as [$builder, $subject]) {
            $data = app($builder)->build($subject);

            // The slot exists and is empty. Phase 9 may fill it; nothing in
            // this phase does, and no figure depends on it.
            $this->assertNull($data->narrative);
            $this->assertNotSame([], $data->sections);
        }
    }

    #[Test]
    public function attaching_a_narrative_cannot_change_a_figure(): void
    {
        $scenario = (new ReportScenario($this->admin()))->full();
        $data = app(BatchSummaryReport::class)->build($scenario->batch);

        $withProse = $data->withNarrative('Some prose written by something other than a PHP builder.');

        $this->assertSame($data->toArray()['sections'], $withProse->toArray()['sections']);
        $this->assertNull($data->narrative);
    }
}
