<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Builders;

use App\Domain\Reporting\Contracts\ReportBuilder;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportScope;
use App\Domain\Reporting\ReportSection;
use App\Models\AssignmentInstance;
use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Where every released assignment in a batch stands.
 *
 * THE LIFECYCLE IS READ, NOT RE-DERIVED. AssignmentSubmission::STATUSES and
 * AssignmentReview::DECISIONS are the vocabulary Phase 4 defined and the
 * assignment services own; this builder counts and lists those values and
 * defines no status of its own. A report that decided for itself what
 * "outstanding" meant would eventually disagree with the workspace showing the
 * same assignment.
 *
 * "Not started" is the absence of a submission row, not a status: Phase 4
 * creates a submission when a participant first engages, so a participant with
 * no row has done nothing yet. That is stated as such rather than counted as a
 * status the domain does not assign.
 */
class AssignmentStatusReport implements ReportBuilder
{
    public function __construct(private readonly ReportScope $scope) {}

    public function key(): string
    {
        return 'assignment_status';
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
        $instances = $this->releasedIn($batch);

        return new ReportData(
            reportKey: $this->key(),
            title: 'Assignment Status',
            subject: $batch,
            customerId: $this->scope->customerIdFor($batch),
            generatedAt: CarbonImmutable::now(),
            sections: [
                $this->assignments($instances, $enrollments),
                $this->submissions($instances, $enrollments),
                $this->reviews($instances),
            ],
            parameters: $parameters,
        );
    }

    /**
     * Every assignment instance belonging to this batch's sessions.
     *
     * Scoped through the batch's own session instances, so an assignment
     * released to another cohort can never appear.
     *
     * @return Collection<int, AssignmentInstance>
     */
    private function releasedIn(Batch $batch)
    {
        return AssignmentInstance::query()
            ->whereIn('session_instance_id', SessionInstance::query()
                ->where('batch_id', $batch->getKey())
                ->select('id'))
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, AssignmentInstance>  $instances
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function assignments($instances, $enrollments): ReportSection
    {
        if ($instances->isEmpty()) {
            return ReportSection::note('Assignments', ['No assignments exist for this batch.']);
        }

        $participants = $enrollments->count();

        return new ReportSection(
            'Assignments',
            array_merge(
                ['Assignment', 'Assignment status', 'Due', 'Participants'],
                array_map('ucfirst', AssignmentSubmission::STATUSES),
                ['No submission row'],
            ),
            $instances->map(function (AssignmentInstance $instance) use ($enrollments, $participants): array {
                $submissions = AssignmentSubmission::query()
                    ->where('assignment_instance_id', $instance->getKey())
                    ->whereIn('enrollment_id', $enrollments->pluck('id'))
                    ->get();

                $byStatus = $submissions->countBy(fn (AssignmentSubmission $s): string => (string) $s->status);

                return array_merge(
                    [
                        (string) $instance->title,
                        (string) $instance->status,
                        $instance->due_at?->toDateTimeString() ?? '',
                        (string) $participants,
                    ],
                    array_map(
                        fn (string $status): string => (string) ($byStatus[$status] ?? 0),
                        AssignmentSubmission::STATUSES,
                    ),
                    // Absence of a row, not a status the domain assigns.
                    [(string) max(0, $participants - $submissions->count())],
                );
            })->all(),
            [
                'Statuses are the assignment lifecycle Phase 4 defines. "No submission row" counts '
                .'participants who have not engaged at all, which is the absence of a record '
                .'rather than a status.',
            ],
        );
    }

    /**
     * @param  Collection<int, AssignmentInstance>  $instances
     * @param  Collection<int, Enrollment>  $enrollments
     */
    private function submissions($instances, $enrollments): ReportSection
    {
        if ($instances->isEmpty() || $enrollments->isEmpty()) {
            return ReportSection::note('Submissions', ['Nothing has been submitted.']);
        }

        $submissions = AssignmentSubmission::query()
            ->whereIn('assignment_instance_id', $instances->pluck('id'))
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->orderBy('assignment_instance_id')
            ->orderBy('enrollment_id')
            ->get();

        if ($submissions->isEmpty()) {
            return ReportSection::note('Submissions', ['Nothing has been submitted.']);
        }

        return new ReportSection(
            'Submissions',
            ['Assignment', 'Participant', 'Status', 'Attempt', 'Submitted at', 'Decisions'],
            $submissions->map(fn (AssignmentSubmission $s): array => [
                (string) ($s->assignmentInstance?->title ?? ''),
                (string) ($s->enrollment?->customer?->name ?? ('Enrolment '.$s->enrollment_id)),
                (string) $s->status,
                (string) $s->attempt_number,
                $s->submitted_at?->toDateTimeString() ?? '',
                (string) $s->reviews()->count(),
            ])->all(),
        );
    }

    /**
     * The decision history - returned, resubmitted, returned again, accepted.
     *
     * @param  Collection<int, AssignmentInstance>  $instances
     */
    private function reviews($instances): ReportSection
    {
        if ($instances->isEmpty()) {
            return ReportSection::note('Review history', ['No reviews recorded.']);
        }

        $reviews = AssignmentReview::query()
            ->whereIn('assignment_submission_id', AssignmentSubmission::query()
                ->whereIn('assignment_instance_id', $instances->pluck('id'))
                ->select('id'))
            ->orderBy('assignment_submission_id')
            ->orderBy('attempt_number')
            ->get();

        if ($reviews->isEmpty()) {
            return ReportSection::note('Review history', ['No reviews recorded.']);
        }

        return new ReportSection(
            'Review history',
            ['Assignment', 'Participant', 'Attempt', 'Decision', 'Reviewed at', 'Remark'],
            $reviews->map(function (AssignmentReview $review): array {
                $submission = $review->assignmentSubmission()->first();

                return [
                    (string) ($submission?->assignmentInstance?->title ?? ''),
                    (string) ($submission?->enrollment?->customer?->name ?? ''),
                    (string) $review->attempt_number,
                    (string) $review->decision,
                    $review->reviewed_at?->toDateTimeString() ?? '',
                    (string) ($review->remark ?? ''),
                ];
            })->all(),
            [
                'Every decision is listed, not just the latest. A submission that was returned '
                .'twice before being accepted says so here.',
            ],
        );
    }
}
