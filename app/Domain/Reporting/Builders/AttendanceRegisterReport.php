<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Builders;

use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportScope;
use App\Domain\Reporting\ReportSection;
use App\Domain\Sessions\AttendanceCalculator;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The attendance register for one batch.
 *
 * [CLIENT DECISION - ATTENDANCE WEIGHTING]
 *
 * The register itself is entirely computable: who was marked what, at which
 * session, and how many marks of each kind a participant has. Counting marks
 * is not weighting them.
 *
 * The two figures that ARE weighted - the current percentage and whether a
 * participant can still reach 90% - are not. How `late` and `excused` affect
 * the numerator and the denominator is unanswered, and the two questions are
 * independent: removing an excused session raises the percentage while
 * counting it as absent lowers it.
 *
 * So this report asks the container for an AttendanceWeighting. If one is
 * bound, the existing AttendanceCalculator produces both figures and this
 * builder does no arithmetic of its own. If none is bound, the report SAYS SO,
 * by name, in place of each figure. It never prints a number derived from an
 * assumed rule - a report that quietly guessed a contractual completion figure
 * would be worse than one that admits the gap, because nobody reading it would
 * know to doubt it.
 */
class AttendanceRegisterReport implements ReportBuilder
{
    public function __construct(private readonly ReportScope $scope) {}

    public function key(): string
    {
        return 'attendance_register';
    }

    public function subjectType(): string
    {
        return Batch::class;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function build(Model $subject, array $parameters = []): ReportData
    {
        $this->scope->assertSubjectType($subject, Batch::class);
        /** @var Batch $batch */
        $batch = $subject;

        $sessions = SessionInstance::query()
            ->where('batch_id', $batch->getKey())
            ->orderBy('planned_date')
            ->orderBy('id')
            ->get();

        // Scoped through the batch, never through a supplied id.
        $enrollments = $this->scope->enrollmentsIn($batch);

        return new ReportData(
            reportKey: $this->key(),
            title: 'Attendance Register',
            subject: $batch,
            customerId: $this->scope->customerIdFor($batch),
            generatedAt: CarbonImmutable::now(),
            sections: [
                $this->register($sessions, $enrollments),
                $this->markCounts($enrollments),
                $this->completion($enrollments, $sessions),
            ],
            parameters: $parameters,
        );
    }

    /**
     * One row per participant, one column per session, each cell the recorded
     * mark verbatim.
     *
     * @param  Collection<int, SessionInstance>  $sessions
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function register($sessions, $enrollments): ReportSection
    {
        if ($sessions->isEmpty() || $enrollments->isEmpty()) {
            return ReportSection::note('Register', ['Nothing to register: no sessions, or no participants.']);
        }

        $marks = SessionAttendance::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->groupBy('enrollment_id');

        $columns = array_merge(
            ['Participant'],
            $sessions->map(fn (SessionInstance $s): string => (string) ($s->sessionTemplate?->title
                ?? $s->planned_date?->toDateString()
                ?? ('Session '.$s->getKey())))->all(),
        );

        $rows = $enrollments->map(function (Enrollment $enrollment) use ($sessions, $marks): array {
            $mine = ($marks->get($enrollment->getKey()) ?? collect())->keyBy('session_instance_id');

            return array_merge(
                [(string) ($enrollment->customer?->name ?? ('Enrolment '.$enrollment->getKey()))],
                $sessions->map(fn (SessionInstance $s): string => (string) (
                    $mine->get($s->getKey())?->status ?? 'Not marked'
                ))->all(),
            );
        })->all();

        return new ReportSection('Register', $columns, $rows);
    }

    /**
     * How many marks of each kind each participant has.
     *
     * A count, not a weighting: no status is treated as worth more or less
     * than another here.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function markCounts($enrollments): ReportSection
    {
        if ($enrollments->isEmpty()) {
            return ReportSection::note('Marks by status', ['No participants.']);
        }

        $marks = SessionAttendance::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->groupBy('enrollment_id');

        return new ReportSection(
            'Marks by status',
            array_merge(['Participant'], array_map('ucfirst', SessionAttendance::STATUSES), ['Marked']),
            $enrollments->map(function (Enrollment $enrollment) use ($marks): array {
                $mine = $marks->get($enrollment->getKey()) ?? collect();
                $byStatus = $mine->countBy(fn (SessionAttendance $a): string => (string) $a->status);

                return array_merge(
                    [(string) ($enrollment->customer?->name ?? ('Enrolment '.$enrollment->getKey()))],
                    array_map(
                        fn (string $status): string => (string) ($byStatus[$status] ?? 0),
                        SessionAttendance::STATUSES,
                    ),
                    [(string) $mine->count()],
                );
            })->all(),
            [
                'These are counts of recorded marks. They are not weighted: how `late` and '
                .'`excused` count toward completion is an open client decision.',
            ],
        );
    }

    /**
     * The 90% figures - or an explicit statement that they cannot be produced.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, SessionInstance>  $sessions
     */
    private function completion($enrollments, $sessions): ReportSection
    {
        if (! app()->bound(AttendanceWeighting::class)) {
            return ReportSection::note('Completion against the 90% rule', [
                '[CLIENT DECISION - ATTENDANCE WEIGHTING] Not available.',
                'The current attendance percentage and the "can still reach 90%" warning both '
                .'depend on how `late` and `excused` affect the numerator and the denominator of '
                .'the rule. That is unanswered, and the two parts are independent: removing an '
                .'excused session from the total raises the percentage, while counting it as '
                .'absent lowers it.',
                'No percentage is shown rather than one derived from an assumed rule. Both '
                .'figures appear here automatically once an AttendanceWeighting is bound.',
            ]);
        }

        // Bound: the existing calculator owns both figures. This builder still
        // performs no arithmetic of its own.
        $calculator = app(AttendanceCalculator::class);
        $total = $sessions
            ->reject(fn (SessionInstance $s): bool => $s->status === SessionInstance::STATUS_CANCELLED)
            ->count();

        return new ReportSection(
            'Completion against the 90% rule',
            ['Participant', 'Current %', 'Can still reach 90%'],
            $enrollments->map(function (Enrollment $enrollment) use ($calculator, $total): array {
                $current = $calculator->currentPercentage($enrollment);

                return [
                    (string) ($enrollment->customer?->name ?? ('Enrolment '.$enrollment->getKey())),
                    $current === null ? 'Not recorded' : number_format($current * 100, 2, '.', ''),
                    $calculator->isStillReachable($enrollment, $total) ? 'Yes' : 'No',
                ];
            })->all(),
            [
                'Computed by AttendanceCalculator from the bound weighting. The threshold is '
                .number_format(AttendanceCalculator::COMPLETION_THRESHOLD * 100, 0).'%.',
            ],
        );
    }
}
