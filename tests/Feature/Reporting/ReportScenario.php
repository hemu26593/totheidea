<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Assignments\AssignmentReleaseService;
use App\Domain\Assignments\AssignmentReviewService;
use App\Domain\Assignments\AssignmentSubmissionService;
use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Domain\Forms\SubmissionService;
use App\Domain\Scoring\ScoringService;
use App\Domain\Sessions\AttendanceService;
use App\Domain\Sessions\SessionSchedulingService;
use App\Domain\Trackers\ActionItemService;
use App\Enums\QuestionType;
use App\Models\AssignmentInstance;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use App\Models\SkillArea;
use App\Models\User;

/**
 * A coherent programme in miniature, for the report tests to read.
 *
 * One batch, its own participants, real sessions, real attendance marks, a
 * released assignment with a real submission and review, and action items -
 * all built through the domain services that own those rules, never by
 * inserting rows behind them. A report tested against fabricated data proves
 * only that the fabrication matched the assertion.
 */
final class ReportScenario
{
    public Program $program;

    public Batch $batch;

    /** @var array<int, Customer> */
    public array $customers = [];

    /** @var array<int, Enrollment> */
    public array $enrollments = [];

    /** @var array<int, SessionInstance> */
    public array $sessions = [];

    public ?AssignmentInstance $assignment = null;

    /**
     * Distinguishes one scenario's businesses from another's.
     *
     * Isolation tests build two whole programmes and assert that neither
     * appears in the other's report. If both used "Business A", the assertion
     * could not tell a leak from a coincidence - so every scenario gets its
     * own letter range.
     */
    private static int $sequence = 0;

    /** @var array<int, string> */
    public array $names = [];

    public function __construct(public User $actor, int $participants = 2)
    {
        $this->program = Program::factory()->create(['session_count' => 2]);
        $this->batch = Batch::factory()->create(['program_id' => $this->program->getKey()]);

        $prefix = 'P'.(++self::$sequence);

        for ($i = 0; $i < $participants; $i++) {
            $name = $prefix.' Business '.chr(65 + $i);
            $this->names[] = $name;
            $customer = Customer::factory()->create(['name' => $name]);
            $this->customers[] = $customer;
            $this->enrollments[] = Enrollment::factory()->create([
                'customer_id' => $customer->getKey(),
                'batch_id' => $this->batch->getKey(),
            ]);
        }
    }

    /**
     * Two scheduled sessions, the first held.
     */
    public function withSessions(): self
    {
        $scheduling = app(SessionSchedulingService::class);

        foreach ([1, 2] as $sequence) {
            $template = SessionTemplate::factory()->create([
                'program_id' => $this->program->getKey(),
                'sequence' => $sequence,
                'title' => "Session {$sequence}",
            ]);

            $this->sessions[] = $scheduling->schedule(
                $this->batch,
                $template,
                '2026-10-0'.$sequence,
                $this->actor,
            );
        }

        $this->sessions[0] = $scheduling->complete($this->sessions[0], $this->actor, '2026-10-01');

        return $this;
    }

    /**
     * Mark the held session: the first participant present, the rest absent.
     */
    public function withAttendance(): self
    {
        $attendance = app(AttendanceService::class);

        foreach ($this->enrollments as $i => $enrollment) {
            $attendance->mark(
                $this->sessions[0]->fresh(),
                $enrollment,
                $i === 0 ? SessionAttendance::STATUS_PRESENT : SessionAttendance::STATUS_ABSENT,
                $this->actor,
            );
        }

        return $this;
    }

    /**
     * One released assignment; the first participant submits and is returned.
     */
    public function withAssignment(): self
    {
        $release = app(AssignmentReleaseService::class);

        $instance = $release->stageAdHoc(
            $this->sessions[0]->fresh(),
            'Draft your Q1 time grid',
            '2026-10-20 17:00:00',
            'Fill every quadrant.',
            actor: $this->actor,
        );

        $this->assignment = $release->release($instance, $this->actor);

        $submission = app(AssignmentSubmissionService::class)->submit(
            $this->assignment,
            $this->enrollments[0],
            'My first attempt',
            $this->actor,
        );

        app(AssignmentReviewService::class)->returnForRework($submission, $this->actor, 'Needs the numbers.');

        return $this;
    }

    /**
     * A published, answered and SCORED form for the first participant.
     *
     * Built through the real form engine and the real ScoringService, so the
     * report reads the values that service actually wrote rather than rows a
     * test invented.
     */
    public function withScoring(): self
    {
        $builder = app(FormBuilderService::class);
        $publishing = app(FormPublishingService::class);
        $skillArea = SkillArea::factory()->create(['name' => 'Delegation']);

        $version = $builder->createDraftVersion(FormTemplate::factory()->scored()->create());
        $section = $builder->addSection($version, 'Assessment', 0);
        $question = $builder->addQuestion(
            $version, $section, QuestionType::Number, 'Rate your delegation', 0,
            ['max_score' => 10, 'skill_area_id' => $skillArea->getKey()],
        );
        $publishing->publish($version->fresh(), $this->actor);

        $submissions = app(SubmissionService::class);
        $submission = $submissions->startDraft($this->enrollments[0], $version->fresh(), $this->actor);
        $submissions->answer($submission, $question, ['value_number' => 7]);
        $submission = $submissions->submit($submission, $this->actor);

        app(ScoringService::class)->score($submission->fresh());

        return $this;
    }

    public function withActionItems(): self
    {
        $items = app(ActionItemService::class);

        $items->create($this->enrollments[0], 'Open commitment', actor: $this->actor);
        $done = $items->create($this->enrollments[0], 'Finished commitment', actor: $this->actor);
        $items->complete($done, $this->actor);

        return $this;
    }

    public function full(): self
    {
        return $this->withSessions()->withAttendance()->withAssignment()->withActionItems();
    }

    /**
     * Everything, including a scored form.
     */
    public function fullyScored(): self
    {
        return $this->full()->withScoring();
    }
}
