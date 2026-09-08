<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Builders;

use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportScope;
use App\Domain\Reporting\ReportSection;
use App\Domain\Trackers\TargetVsActualCalculator;
use App\Models\ActionItem;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\SubmissionScore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One participant's progress through the programme.
 *
 * EVERY FIGURE IS READ OR COUNTED, NEVER INVENTED. Scores come from the rows
 * ScoringService already wrote; sessions, attendance, assignments and actions
 * are counted from their own tables. This builder contains no scoring formula
 * of its own, and adding one would put a second, divergent definition of a
 * score into the system.
 *
 * WHAT IS DELIBERATELY ABSENT:
 *   - Any Average / Good / Better / Best label. Those are categorical answers
 *     a participant gives, not a grade this report awards, and no numeric
 *     mapping for them exists anywhere.
 *   - Any heat-map band or weak-skill classification. The thresholds are
 *     deferred item L2, and ScoringService::bandFor() refuses for the same
 *     reason.
 *   - Any attendance percentage. That depends on the unresolved weighting
 *     decision; see AttendanceRegisterReport, which states the dependency
 *     rather than guessing at it.
 */
class ParticipantProgressReport implements ReportBuilder
{
    public function __construct(
        private readonly ReportScope $scope,
        private readonly TargetVsActualCalculator $targetVsActual,
    ) {}

    public function key(): string
    {
        return 'participant_progress';
    }

    public function subjectType(): string
    {
        return Enrollment::class;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function build(Model $subject, array $parameters = []): ReportData
    {
        $this->scope->assertSubjectType($subject, Enrollment::class);
        /** @var Enrollment $enrollment */
        $enrollment = $subject;
        $customerId = $this->scope->customerIdFor($enrollment);

        return new ReportData(
            reportKey: $this->key(),
            title: 'Participant Progress Report',
            subject: $enrollment,
            customerId: $customerId,
            generatedAt: CarbonImmutable::now(),
            sections: [
                $this->participant($enrollment),
                $this->intake($enrollment),
                $this->scores($enrollment),
                $this->sessions($enrollment),
                $this->assignments($enrollment),
                $this->actions($enrollment),
                $this->mmd($enrollment),
            ],
            parameters: $parameters,
        );
    }

    private function participant(Enrollment $enrollment): ReportSection
    {
        $customer = $enrollment->customer()->first();
        $batch = $enrollment->batch()->first();

        return new ReportSection('Participant', ['Field', 'Value'], [
            ['Business', (string) ($customer?->name ?? '')],
            ['Batch', (string) ($batch?->name ?? '')],
            ['Enrolment status', (string) $enrollment->status],
            ['Enrolled at', $enrollment->enrolled_at?->toDateString() ?? ''],
        ]);
    }

    private function intake(Enrollment $enrollment): ReportSection
    {
        $submissions = FormSubmission::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderBy('id')
            ->get();

        if ($submissions->isEmpty()) {
            return ReportSection::note('Forms', ['No forms have been started.']);
        }

        return new ReportSection('Forms', ['Form version', 'Status', 'Submitted at'], $submissions
            ->map(fn (FormSubmission $s): array => [
                (string) $s->form_version_id,
                (string) $s->status,
                $s->submitted_at?->toDateString() ?? '',
            ])
            ->all());
    }

    /**
     * Scores exactly as ScoringService stored them.
     *
     * The newest row per score type and skill area is the current score - the
     * table is append-only, so recomputation adds rather than overwrites.
     */
    private function scores(Enrollment $enrollment): ReportSection
    {
        $scores = SubmissionScore::query()
            ->whereIn('form_submission_id', FormSubmission::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->select('id'))
            ->orderBy('form_submission_id')
            ->orderBy('score_type')
            ->orderBy('computed_at')
            ->get();

        if ($scores->isEmpty()) {
            return ReportSection::note('Scores', ['Nothing has been scored yet.']);
        }

        // Newest per (submission, type, area). No arithmetic - a selection.
        $current = $scores
            ->groupBy(fn (SubmissionScore $s): string => implode('|', [
                $s->form_submission_id, $s->score_type, $s->skill_area_id ?? 'overall',
            ]))
            ->map(fn ($group) => $group->last());

        return new ReportSection(
            'Scores',
            ['Submission', 'Score type', 'Skill area', 'Raw', 'Max', 'Percentage', 'Scheme', 'Computed at'],
            $current
                ->values()
                ->map(fn (SubmissionScore $s): array => [
                    (string) $s->form_submission_id,
                    (string) $s->score_type,
                    $s->skill_area_id === null ? 'Overall' : (string) $s->skillArea?->name,
                    (string) $s->raw_score,
                    (string) $s->max_score,
                    $s->percentage === null ? '' : (string) $s->percentage,
                    (string) $s->scheme_version,
                    $s->computed_at?->toDateString() ?? '',
                ])
                ->all(),
            [
                'Scores are read from the values ScoringService computed and stored. This report '
                .'applies no banding and no Average / Good / Better / Best mapping: the heat-map '
                .'thresholds are an open client decision (L2).',
            ],
        );
    }

    private function sessions(Enrollment $enrollment): ReportSection
    {
        $instances = SessionInstance::query()
            ->where('batch_id', $enrollment->batch_id)
            ->orderBy('planned_date')
            ->orderBy('id')
            ->get();

        if ($instances->isEmpty()) {
            return ReportSection::note('Sessions', ['No sessions have been scheduled for this batch.']);
        }

        $marks = SessionAttendance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->get()
            ->keyBy('session_instance_id');

        return new ReportSection(
            'Sessions',
            ['Session', 'Planned', 'Actual', 'Session status', 'Attendance'],
            $instances->map(function (SessionInstance $instance) use ($marks): array {
                $mark = $marks->get($instance->getKey());

                return [
                    (string) ($instance->sessionTemplate?->title ?? ''),
                    $instance->planned_date?->toDateString() ?? '',
                    $instance->actual_date?->toDateString() ?? '',
                    (string) $instance->status,
                    // The recorded mark, verbatim. Not weighted, not scored.
                    $mark === null ? 'Not marked' : (string) $mark->status,
                ];
            })->all(),
        );
    }

    private function assignments(Enrollment $enrollment): ReportSection
    {
        $submissions = AssignmentSubmission::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderBy('id')
            ->get();

        if ($submissions->isEmpty()) {
            return ReportSection::note('Assignments', ['No assignments have been filed.']);
        }

        return new ReportSection(
            'Assignments',
            ['Assignment', 'Status', 'Attempt', 'Submitted at', 'Decisions'],
            $submissions->map(fn (AssignmentSubmission $s): array => [
                (string) ($s->assignmentInstance?->title ?? ''),
                (string) $s->status,
                (string) $s->attempt_number,
                $s->submitted_at?->toDateString() ?? '',
                (string) $s->reviews()->count(),
            ])->all(),
        );
    }

    private function actions(Enrollment $enrollment): ReportSection
    {
        $items = ActionItem::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return ReportSection::note('Action plan', ['The action list is empty.']);
        }

        $byStatus = $items->countBy(fn (ActionItem $i): string => (string) $i->status);

        return new ReportSection(
            'Action plan',
            ['Status', 'Count'],
            array_map(
                fn (string $status): array => [$status, (string) ($byStatus[$status] ?? 0)],
                ActionItem::STATUSES,
            ),
        );
    }

    /**
     * Target versus actual, through the existing calculator.
     *
     * [B1 CLIENT DECISION - MMD GRAIN] is untouched here: that calculator
     * aggregates over a date range, which returns the same figure whether a
     * day holds one business-level row or several contributor rows. This
     * report therefore needs no opinion about the grain.
     */
    private function mmd(Enrollment $enrollment): ReportSection
    {
        $scorecard = $this->targetVsActual->scorecard($enrollment);

        if ($scorecard === []) {
            return ReportSection::note('MMD targets', ['No targets have been set.']);
        }

        return new ReportSection(
            'MMD targets',
            ['Metric', 'Period start', 'Period end', 'Target', 'Actual', 'Variance'],
            array_map(fn (array $row): array => [
                (string) $row['metric'],
                (string) $row['period_start'],
                (string) $row['period_end'],
                $this->number($row['target']),
                $row['actual'] === null ? 'Not recorded' : $this->number($row['actual']),
                $row['variance'] === null ? '' : $this->number($row['variance']),
            ], $scorecard),
            [
                'Actuals are summed over each target period, which is correct whether the daily '
                .'dashboard is filed once per business or once per contributor (B1 is open).',
            ],
        );
    }

    /**
     * Fixed decimals, always a dot. Locale-independent so a CSV parses the
     * same way wherever it is opened.
     */
    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
