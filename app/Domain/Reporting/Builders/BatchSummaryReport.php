<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Builders;

use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportScope;
use App\Domain\Reporting\ReportSection;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Models\ActionItem;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A batch at a glance.
 *
 * COUNTS OF THINGS THAT EXIST - nothing more. Every figure here is the size of
 * a set: how many participants, how many sessions in each state, how many
 * marks of each status, how many submissions at each stage, how many actions
 * open. No ratio, no index, no score, no KPI is invented, because none is
 * defined.
 *
 * NO MMD AGGREGATION. A batch spans several BUSINESSES, so a batch-level Fund
 * IN or sales total would sum unrelated companies' finances into one figure
 * that means nothing and reveals plenty. MMD belongs to the participant
 * report, where it has an owner.
 *
 * NO ATTENDANCE PERCENTAGE, for the reason the attendance register gives at
 * length: the weighting is an open client decision.
 */
class BatchSummaryReport implements ReportBuilder
{
    public function __construct(private readonly ReportScope $scope) {}

    public function key(): string
    {
        return 'batch_summary';
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

        $enrollments = $this->scope->enrollmentsIn($batch);

        return new ReportData(
            reportKey: $this->key(),
            title: 'Batch Summary',
            subject: $batch,
            customerId: $this->scope->customerIdFor($batch),
            generatedAt: CarbonImmutable::now(),
            sections: [
                $this->batch($batch, $enrollments),
                $this->participants($enrollments),
                $this->sessions($batch),
                $this->attendance($enrollments),
                $this->assignments($batch, $enrollments),
                $this->forms($enrollments),
                $this->actions($enrollments),
            ],
            parameters: $parameters,
        );
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function batch(Batch $batch, $enrollments): ReportSection
    {
        return new ReportSection('Batch', ['Field', 'Value'], [
            ['Name', (string) $batch->name],
            ['Code', (string) $batch->code],
            ['Programme', (string) ($batch->program?->name ?? '')],
            ['Starts on', $batch->starts_on?->toDateString() ?? ''],
            ['Ends on', $batch->ends_on?->toDateString() ?? ''],
            ['Status', (string) $batch->status],
            ['Participants', (string) $enrollments->count()],
        ]);
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function participants($enrollments): ReportSection
    {
        if ($enrollments->isEmpty()) {
            return ReportSection::note('Participants', ['Nobody is enrolled in this batch.']);
        }

        $byStatus = $enrollments->countBy(fn (Enrollment $e): string => (string) $e->status);

        return new ReportSection(
            'Participants',
            ['Enrolment status', 'Count'],
            $byStatus->map(fn (int $count, string $status): array => [$status, (string) $count])
                ->values()
                ->all(),
        );
    }

    private function sessions(Batch $batch): ReportSection
    {
        $sessions = SessionInstance::query()
            ->where('batch_id', $batch->getKey())
            ->get();

        if ($sessions->isEmpty()) {
            return ReportSection::note('Sessions', ['No sessions have been scheduled.']);
        }

        $byStatus = $sessions->countBy(fn (SessionInstance $s): string => (string) $s->status);

        return new ReportSection(
            'Sessions',
            ['Session status', 'Count'],
            array_map(
                fn (string $status): array => [$status, (string) ($byStatus[$status] ?? 0)],
                SessionInstance::STATUSES,
            ),
        );
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function attendance($enrollments): ReportSection
    {
        if ($enrollments->isEmpty()) {
            return ReportSection::note('Attendance', ['No participants.']);
        }

        $marks = SessionAttendance::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get();

        $byStatus = $marks->countBy(fn (SessionAttendance $a): string => (string) $a->status);

        $notes = ['Counts of recorded marks across the batch.'];

        if (! app()->bound(AttendanceWeighting::class)) {
            $notes[] = '[CLIENT DECISION - ATTENDANCE WEIGHTING] No completion percentage is '
                .'shown: how `late` and `excused` weigh in the 90% rule is unanswered, and this '
                .'report will not print a figure derived from an assumed rule.';
        }

        return new ReportSection(
            'Attendance',
            ['Mark', 'Count'],
            array_merge(
                array_map(
                    fn (string $status): array => [$status, (string) ($byStatus[$status] ?? 0)],
                    SessionAttendance::STATUSES,
                ),
                [['Total marks', (string) $marks->count()]],
            ),
            $notes,
        );
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function assignments(Batch $batch, $enrollments): ReportSection
    {
        $instances = AssignmentInstance::query()
            ->whereIn('session_instance_id', SessionInstance::query()
                ->where('batch_id', $batch->getKey())
                ->select('id'))
            ->get();

        if ($instances->isEmpty()) {
            return ReportSection::note('Assignments', ['No assignments exist for this batch.']);
        }

        $submissions = $enrollments->isEmpty()
            ? collect()
            : AssignmentSubmission::query()
                ->whereIn('assignment_instance_id', $instances->pluck('id'))
                ->whereIn('enrollment_id', $enrollments->pluck('id'))
                ->get();

        $byInstanceStatus = $instances->countBy(fn (AssignmentInstance $i): string => (string) $i->status);
        $bySubmissionStatus = $submissions->countBy(fn (AssignmentSubmission $s): string => (string) $s->status);

        $rows = array_map(
            fn (string $status): array => ['Assignment: '.$status, (string) ($byInstanceStatus[$status] ?? 0)],
            AssignmentInstance::STATUSES,
        );

        foreach (AssignmentSubmission::STATUSES as $status) {
            $rows[] = ['Submission: '.$status, (string) ($bySubmissionStatus[$status] ?? 0)];
        }

        return new ReportSection('Assignments', ['Measure', 'Count'], $rows);
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function forms($enrollments): ReportSection
    {
        if ($enrollments->isEmpty()) {
            return ReportSection::note('Forms', ['No participants.']);
        }

        $submissions = FormSubmission::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get();

        if ($submissions->isEmpty()) {
            return ReportSection::note('Forms', ['No forms have been started.']);
        }

        return new ReportSection(
            'Forms',
            ['Submission status', 'Count'],
            $submissions
                ->countBy(fn (FormSubmission $s): string => (string) $s->status)
                ->map(fn (int $count, string $status): array => [$status, (string) $count])
                ->values()
                ->all(),
        );
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function actions($enrollments): ReportSection
    {
        if ($enrollments->isEmpty()) {
            return ReportSection::note('Action plan', ['No participants.']);
        }

        $items = ActionItem::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get();

        if ($items->isEmpty()) {
            return ReportSection::note('Action plan', ['No action items.']);
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
}
