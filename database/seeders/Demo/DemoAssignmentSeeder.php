<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Assignments\AssignmentReviewService;
use App\Domain\Assignments\AssignmentSubmissionService;
use App\Models\AssignmentInstance;
use App\Models\SessionInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Assignments released off the sessions that have been held, and the state
 * each participant's work is in.
 *
 * The mixture is deliberate and fixed: some accepted, some waiting for review,
 * some returned for rework, some overdue and untouched. A demonstration in
 * which every participant is in the same state shows nothing about the screens
 * that exist to tell them apart.
 *
 * Overdue is not a status here, and is not set as one. An assignment is
 * overdue because its due date has passed and nothing has been submitted,
 * which is what the application itself computes.
 */
class DemoAssignmentSeeder extends Seeder
{
    /**
     * How each business handles its work. Fixed so a reseed tells the same
     * story: the businesses that are struggling stay the ones that struggle.
     *
     * @var array<string, string>
     */
    private const BEHAVIOUR = [
        'BMP-APEX' => 'diligent',
        'BMP-SHREEJI' => 'diligent',
        'BMP-VARDHAN' => 'late',
        'BMP-MERIDIAN' => 'reworking',
        'BMP-ARVIND' => 'diligent',
        'BMP-ZENITH' => 'waiting',
        'BMP-PRAKASH' => 'late',
        'BMP-WESTERN' => 'waiting',
        'BMP-SANKALP' => 'reworking',
        'BMP-GIRIRAJ' => 'late',
        'BMP-NIRMAN' => 'diligent',
        'BMP-SAMARTH' => 'waiting',
    ];

    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $consultants = DemoTeamSeeder::consultants();
        $reviewer = $consultants[0];

        $release = app(AssignmentReleaseService::class);
        $submissions = app(AssignmentSubmissionService::class);
        $reviews = app(AssignmentReviewService::class);

        $held = SessionInstance::query()
            ->where('status', SessionInstance::STATUS_COMPLETED)
            ->with(['sessionTemplate.assignmentTemplates', 'batch.enrollments.customer'])
            ->orderBy('id')
            ->get();

        foreach ($held as $session) {
            foreach ($session->sessionTemplate?->assignmentTemplates ?? [] as $template) {
                $instance = AssignmentInstance::query()
                    ->where('session_instance_id', $session->getKey())
                    ->where('assignment_template_id', $template->getKey())
                    ->first();

                if ($instance === null) {
                    $dueAt = CarbonImmutable::parse($session->actual_date ?? $session->planned_date)
                        ->addDays((int) ($template->default_due_days ?? 10));

                    $instance = $release->release(
                        $release->stageFromTemplate($session, $template, $dueAt->toDateTimeString(), $actor),
                        $actor,
                    );
                }

                $this->workThrough($instance, $session, $submissions, $reviews, $actor, $reviewer);
            }
        }
    }

    private function workThrough(
        AssignmentInstance $instance,
        SessionInstance $session,
        AssignmentSubmissionService $submissions,
        AssignmentReviewService $reviews,
        User $actor,
        User $reviewer,
    ): void {
        foreach ($session->batch?->enrollments ?? [] as $enrollment) {
            $code = (string) $enrollment->customer?->code;
            $behaviour = self::BEHAVIOUR[$code] ?? 'waiting';

            if ($submissions->find($instance, $enrollment) !== null) {
                continue;
            }

            // Somebody who has not started leaves the assignment overdue once
            // its due date passes. That is the state, not a status.
            if ($behaviour === 'late') {
                continue;
            }

            $submissions->start($instance, $enrollment, $actor);

            $submitted = $submissions->submit(
                $instance,
                $enrollment,
                $this->bodyFor($instance, $enrollment->customer?->name ?? 'the business'),
                $actor,
            );

            if ($behaviour === 'diligent') {
                $reviews->accept($submitted, $reviewer, 'Clear and specific. Carry this into the next session.');
            }

            if ($behaviour === 'reworking') {
                $reviews->returnForRework(
                    $submitted,
                    $reviewer,
                    'Good start, but the owner and the target date are missing. Please add both and resubmit.',
                );
            }

            // 'waiting' leaves the submission submitted and unreviewed, which
            // is what fills the "pending review" figure on the dashboard.
        }
    }

    private function bodyFor(AssignmentInstance $instance, string $business): string
    {
        $title = (string) ($instance->title ?? $instance->assignmentTemplate?->title ?? 'this assignment');

        return match (true) {
            str_contains($title, '12-month') => "Twelve-month objective for {$business}: lift turnover by 35% without a second shift, "
                .'measured on monthly despatch value and reviewed on the first Monday of each month.',
            str_contains($title, 'bottlenecks') => "Top five constraints at {$business}:\n"
                ."1. Quotation costing waits on one person.\n"
                ."2. Production plan is not visible after mid-morning.\n"
                ."3. Follow-up depends on memory, not a register.\n"
                ."4. Rejections are counted but not owned.\n"
                .'5. Receivables are chased only when cash is short.',
            str_contains($title, 'sales process') => "Enquiry to order at {$business}, as it actually runs: enquiry arrives by phone or email, "
                .'is noted by whoever answers, passed for costing, quoted, then followed up if somebody remembers. '
                .'Steps 2 and 5 have no named owner.',
            str_contains($title, 'delegation') => 'Decisions currently made by the owner: purchase approval, quotation pricing, leave approval, '
                .'despatch priority. Purchase approval below 50,000 and leave approval can move this quarter.',
            str_contains($title, 'KPI') => "Weekly numbers for {$business}: enquiries received, quotations issued, orders won, "
                .'despatch value, on-time despatch %, rejections %, receivables over 90 days, cash at bank.',
            default => "Ninety-day plan for {$business}: one priority per function, each with a named owner and a dated review, "
                .'starting with the constraint identified in session 1.',
        };
    }
}
