<?php

declare(strict_types=1);

namespace Tests\Feature\Trackers;

use App\Domain\Trackers\ActionItemFeeder;
use App\Domain\Trackers\ActionItemService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\ActionItem;
use App\Models\AssignmentInstance;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\SessionInstance;
use App\Models\SubmissionScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The participant's action list, and the auto-feed that populates it.
 *
 * Distinct from the Day Plan. An action item is an outstanding commitment with
 * a reason for existing; a day plan item is scheduled work on one date. The
 * negative assertions below are what keep them apart.
 */
class ActionPlanTest extends TestCase
{
    use RefreshDatabase;

    private ActionItemService $items;

    private ActionItemFeeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->items = app(ActionItemService::class);
        $this->feeder = app(ActionItemFeeder::class);
    }

    // --- Creation and lifecycle -----------------------------------------------

    #[Test]
    public function an_item_is_created_with_a_due_date_and_a_priority(): void
    {
        $enrollment = Enrollment::factory()->create();

        $item = $this->items->create(
            $enrollment, 'Rework the pricing sheet', 'Session 3 follow-up',
            '2026-10-15', ActionItem::PRIORITY_HIGH, $this->admin(),
        );

        $this->assertSame('Rework the pricing sheet', $item->title);
        $this->assertSame('2026-10-15', $item->due_date->toDateString());
        $this->assertSame(ActionItem::PRIORITY_HIGH, $item->priority);
        $this->assertSame(ActionItem::STATUS_OPEN, $item->status);
        $this->assertSame($enrollment->getKey(), $item->enrollment_id);
    }

    #[Test]
    public function the_four_statuses_are_the_only_ones(): void
    {
        $this->assertSame(['open', 'in_progress', 'done', 'dropped'], ActionItem::STATUSES);
    }

    #[Test]
    public function priority_is_an_ordered_label_not_a_score(): void
    {
        $this->assertSame(['low', 'normal', 'high'], ActionItem::PRIORITIES);

        // No numeric mapping exists for these values, here or anywhere.
        foreach (['priority_value', 'priority_score', 'weight', 'rank', 'score'] as $column) {
            $this->assertFalse(Schema::hasColumn('action_items', $column));
        }
    }

    #[Test]
    public function an_unknown_priority_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->items->create(Enrollment::factory()->create(), 'Task', priority: 'urgent');
    }

    #[Test]
    public function completing_an_item_records_when(): void
    {
        $item = $this->items->create(Enrollment::factory()->create(), 'Task', actor: $this->admin());

        $done = $this->items->complete($item, $this->admin());

        $this->assertTrue($done->isDone());
        $this->assertNotNull($done->completed_at);
    }

    #[Test]
    public function only_done_carries_a_completion_timestamp(): void
    {
        $item = $this->items->create(Enrollment::factory()->create(), 'Task', actor: $this->admin());

        $inProgress = $this->items->transitionTo($item, ActionItem::STATUS_IN_PROGRESS, $this->admin());
        $this->assertNull($inProgress->completed_at);

        $dropped = $this->items->drop($inProgress, $this->admin());
        $this->assertNull($dropped->completed_at);
    }

    #[Test]
    public function an_abandoned_commitment_is_dropped_not_deleted(): void
    {
        $item = ActionItem::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never removed');

        $item->delete();
    }

    #[Test]
    public function the_open_list_is_soonest_due_first(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->items->create($enrollment, 'Later', dueDate: '2026-11-01');
        $this->items->create($enrollment, 'Sooner', dueDate: '2026-10-01');
        $this->items->create($enrollment, 'No date');
        $done = $this->items->create($enrollment, 'Finished', dueDate: '2026-09-01');
        $this->items->complete($done);

        $open = $this->items->openFor($enrollment);

        $this->assertSame(['Sooner', 'Later', 'No date'], $open->pluck('title')->all());
    }

    // --- The auto-feed ----------------------------------------------------------

    #[Test]
    public function a_released_assignment_lands_on_the_list(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
            'title' => 'Draft your Q1 time grid',
            'due_at' => '2026-10-20 17:00:00',
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $item = $this->feeder->fromAssignment($instance, $enrollment);

        $this->assertSame('Draft your Q1 time grid', $item->title);
        $this->assertSame('2026-10-20', $item->due_date->toDateString());
        $this->assertSame($instance->getMorphClass(), $item->source_type);
        $this->assertSame($instance->getKey(), (int) $item->source_id);
        $this->assertTrue($item->wasAutoFed());
    }

    #[Test]
    public function the_auto_feed_is_idempotent(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $first = $this->feeder->fromAssignment($instance, $enrollment);
        $second = $this->feeder->fromAssignment($instance, $enrollment);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ActionItem::query()->count());
    }

    #[Test]
    public function idempotency_is_a_lookup_not_a_unique_key(): void
    {
        // A consultant may legitimately add a second manual task about the
        // same assignment; a unique key would forbid it.
        $unique = collect(Schema::getIndexes('action_items'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertFalse($unique->contains(['source_type', 'source_id']));
        $this->assertFalse($unique->contains(['enrollment_id', 'source_type', 'source_id']));
    }

    #[Test]
    public function two_participants_each_get_their_own_item_for_one_assignment(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $one = Enrollment::factory()->create(['batch_id' => $session->batch_id]);
        $two = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $this->feeder->fromAssignment($instance, $one);
        $this->feeder->fromAssignment($instance, $two);

        $this->assertSame(2, ActionItem::query()->count());
    }

    #[Test]
    public function a_scored_skill_area_can_be_raised_onto_the_list(): void
    {
        $score = SubmissionScore::factory()->create();
        $enrollment = Enrollment::factory()->create();

        $item = $this->feeder->fromWeakSkillArea($score, $enrollment, 'Work on delegation');

        $this->assertSame($score->getMorphClass(), $item->source_type);
        $this->assertSame($score->getKey(), (int) $item->source_id);
    }

    #[Test]
    public function the_weak_skill_feed_refuses_to_decide_what_weak_means(): void
    {
        // "Weak" means below a heat-map band, and the band thresholds are not
        // defined by the requirements (deferred item L2) - the same decision
        // that makes ScoringService::bandFor() refuse. Picking one here would
        // raise action items against a standard nobody set.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deferred item L2');

        $this->feeder->feedWeakSkillAreas(
            FormSubmission::factory()->create(),
            Enrollment::factory()->create(),
        );
    }

    #[Test]
    public function no_scoring_band_or_numeric_mapping_was_introduced(): void
    {
        foreach (['band', 'band_label', 'category', 'grade', 'average', 'good', 'better', 'best'] as $column) {
            $this->assertFalse(Schema::hasColumn('action_items', $column));
        }
    }

    #[Test]
    public function a_dangling_source_never_orphans_an_item(): void
    {
        // Provenance, not ownership: the pair carries no foreign key.
        $item = ActionItem::factory()->create([
            'source_type' => 'App\\Models\\AssignmentInstance',
            'source_id' => 999999,
        ]);

        $this->assertNotNull($item->fresh());
        $this->assertNull($item->sourceRecord()->first());

        $sourceFk = collect(Schema::getForeignKeys('action_items'))
            ->filter(fn (array $f): bool => in_array('source_id', $f['columns'], true));

        $this->assertCount(0, $sourceFk);
    }

    // --- Distinct from the Day Plan ------------------------------------------------

    #[Test]
    public function an_action_item_is_not_a_day_plan_item(): void
    {
        $this->assertSame('action_items', (new ActionItem)->getTable());

        // No scheduled-day semantics here.
        foreach (['plan_date', 'planned_start', 'planned_end', 'carried_from_id', 'actual_minutes'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('action_items', $column),
                "action_items must not have [{$column}] - that is the Day Plan."
            );
        }

        // And no commitment semantics on the Day Plan.
        foreach (['source_type', 'source_id', 'priority', 'completed_at', 'due_date'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('day_plan_items', $column),
                "day_plan_items must not have [{$column}] - that is the Action Plan."
            );
        }
    }

    #[Test]
    public function there_is_no_generic_task_table(): void
    {
        foreach (['tasks', 'todos', 'todo_items', 'checklist_items'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    // --- Isolation --------------------------------------------------------------------

    #[Test]
    public function an_item_cannot_be_paired_with_another_participants_list(): void
    {
        $item = ActionItem::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to enrolment');

        $this->items->assertBelongsToEnrollment($item, Enrollment::factory()->create());
    }

    #[Test]
    public function a_grant_for_another_participant_cannot_write_here(): void
    {
        $enrollment = Enrollment::factory()->create();
        $foreign = AccessGrant::factory()->create(['ability' => GrantAbility::SubmitAssignment]);

        $this->expectException(CustomerIsolationException::class);

        $this->items->create($enrollment, 'Not mine', grant: $foreign);
    }

    #[Test]
    public function a_participant_can_complete_their_own_item_through_a_link(): void
    {
        $enrollment = Enrollment::factory()->create();
        $grant = AccessGrant::factory()->create([
            'customer_id' => $enrollment->customer_id,
            'enrollment_id' => $enrollment->getKey(),
            'ability' => GrantAbility::SubmitAssignment,
        ]);

        $item = $this->items->create($enrollment, 'Mine', grant: $grant);

        $this->assertSame(ActorSource::ExternalGrant, $item->source);
        $this->assertSame($grant->getKey(), $item->access_grant_id);
        $this->assertNull($item->created_by);
    }

    #[Test]
    public function the_open_list_never_reaches_another_participant(): void
    {
        $mine = Enrollment::factory()->create();
        $theirs = Enrollment::factory()->create();

        $this->items->create($mine, 'Mine');
        $this->items->create($theirs, 'Theirs');

        $open = $this->items->openFor($mine);

        $this->assertCount(1, $open);
        $this->assertSame('Mine', $open->first()->title);
    }
}
