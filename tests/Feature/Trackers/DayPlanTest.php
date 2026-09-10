<?php

declare(strict_types=1);

namespace Tests\Feature\Trackers;

use App\Domain\Trackers\DayPlanService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The daily execution planner, and the carry-forward that is its whole point.
 *
 * The load-bearing property here is that carrying a task forward COPIES it.
 * Yesterday keeps its unfinished work, and the chain showing how many times
 * something slipped survives - which is the coaching signal the module exists
 * to produce.
 */
class DayPlanTest extends TestCase
{
    use RefreshDatabase;

    private DayPlanService $dayPlans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->dayPlans = app(DayPlanService::class);
    }

    // --- Creation and status ------------------------------------------------

    #[Test]
    public function a_task_is_planned_for_a_date_with_a_time_slot(): void
    {
        $enrollment = Enrollment::factory()->create();

        $item = $this->dayPlans->create(
            $enrollment,
            '2026-10-01',
            'Call the top ten accounts',
            $this->admin(),
            plannedStart: '09:00',
            plannedEnd: '10:30',
        );

        $this->assertSame('2026-10-01', $item->plan_date->toDateString());
        $this->assertSame('Call the top ten accounts', $item->task);
        $this->assertSame(DayPlanItem::STATUS_PLANNED, $item->status);
        $this->assertSame((int) $enrollment->customer_id, (int) $item->customer_id);
    }

    #[Test]
    public function several_tasks_may_share_one_slot_on_one_day(): void
    {
        $enrollment = Enrollment::factory()->create();

        foreach (['One', 'Two', 'Three'] as $task) {
            $this->dayPlans->create($enrollment, '2026-10-01', $task, $this->admin(), plannedStart: '09:00');
        }

        // Deliberately no unique key: a participant may legitimately plan
        // several things in the same slot.
        $this->assertCount(3, $this->dayPlans->forDay($enrollment, '2026-10-01'));
    }

    #[Test]
    public function a_slot_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dayPlans->create(
            Enrollment::factory()->create(),
            '2026-10-01',
            'Impossible',
            $this->admin(),
            plannedStart: '11:00',
            plannedEnd: '09:00',
        );
    }

    #[Test]
    public function completing_a_task_records_the_time_it_took(): void
    {
        $item = $this->dayPlans->create(
            Enrollment::factory()->create(), '2026-10-01', 'Task', $this->admin(),
        );

        $done = $this->dayPlans->complete($item, 45, $this->admin());

        $this->assertTrue($done->isDone());
        $this->assertSame(45, $done->actual_minutes);
    }

    #[Test]
    public function the_three_statuses_are_the_only_ones(): void
    {
        // No invented workflow states.
        $this->assertSame(['planned', 'done', 'carried_forward'], DayPlanItem::STATUSES);
    }

    #[Test]
    public function a_settled_item_is_not_edited(): void
    {
        $item = $this->dayPlans->create(
            Enrollment::factory()->create(), '2026-10-01', 'Task', $this->admin(),
        );
        $this->dayPlans->complete($item, null, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->dayPlans->update($item->fresh(), ['task' => 'Rewritten']);
    }

    // --- Carry-forward -------------------------------------------------------

    #[Test]
    public function carry_forward_copies_the_task_and_leaves_yesterday_intact(): void
    {
        $enrollment = Enrollment::factory()->create();
        $original = $this->dayPlans->create($enrollment, '2026-10-01', 'Unfinished', $this->admin());

        $created = $this->dayPlans->carryForward('2026-10-02');

        $this->assertCount(1, $created);
        $carried = $created->first();

        // Yesterday still shows the task, and says what became of it.
        $this->assertSame(DayPlanItem::STATUS_CARRIED_FORWARD, $original->fresh()->status);
        $this->assertSame('2026-10-01', $original->fresh()->plan_date->toDateString());

        // Today has a NEW row pointing back.
        $this->assertNotSame($original->getKey(), $carried->getKey());
        $this->assertSame('2026-10-02', $carried->plan_date->toDateString());
        $this->assertSame($original->getKey(), $carried->carried_from_id);
        $this->assertSame('Unfinished', $carried->task);
        $this->assertSame(DayPlanItem::STATUS_PLANNED, $carried->status);
    }

    #[Test]
    public function carry_forward_is_not_a_date_mutation(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->dayPlans->create($enrollment, '2026-10-01', 'Unfinished', $this->admin());

        $this->dayPlans->carryForward('2026-10-02');

        // Two rows, not one moved row. Mutating in place would make yesterday
        // retroactively show no unfinished work.
        $this->assertSame(2, DayPlanItem::query()->count());
        $this->assertSame(1, DayPlanItem::query()->whereDate('plan_date', '2026-10-01')->count());
        $this->assertSame(1, DayPlanItem::query()->whereDate('plan_date', '2026-10-02')->count());
    }

    #[Test]
    public function running_carry_forward_twice_creates_no_duplicate(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->dayPlans->create($enrollment, '2026-10-01', 'Unfinished', $this->admin());

        $this->dayPlans->carryForward('2026-10-02');
        $second = $this->dayPlans->carryForward('2026-10-02');

        $this->assertCount(0, $second);
        $this->assertSame(2, DayPlanItem::query()->count());
    }

    #[Test]
    public function carry_forward_is_idempotent_even_if_the_original_is_left_planned(): void
    {
        // A partially-applied run, or a status changed back by hand, must
        // still not produce two copies of one task.
        $enrollment = Enrollment::factory()->create();
        $original = $this->dayPlans->create($enrollment, '2026-10-01', 'Unfinished', $this->admin());

        $this->dayPlans->carryForward('2026-10-02');
        $original->fresh()->forceFill(['status' => DayPlanItem::STATUS_PLANNED])->save();

        $again = $this->dayPlans->carryForward('2026-10-02');

        $this->assertCount(0, $again);
        $this->assertSame(2, DayPlanItem::query()->count());
        $this->assertSame(DayPlanItem::STATUS_CARRIED_FORWARD, $original->fresh()->status);
    }

    #[Test]
    public function a_completed_task_is_never_carried_forward(): void
    {
        $enrollment = Enrollment::factory()->create();
        $item = $this->dayPlans->create($enrollment, '2026-10-01', 'Finished', $this->admin());
        $this->dayPlans->complete($item, null, $this->admin());

        $this->assertCount(0, $this->dayPlans->carryForward('2026-10-02'));
        $this->assertSame(1, DayPlanItem::query()->count());
    }

    #[Test]
    public function the_slip_chain_is_walkable(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->dayPlans->create($enrollment, '2026-10-01', 'Keeps slipping', $this->admin());

        $this->dayPlans->carryForward('2026-10-02');
        $this->dayPlans->carryForward('2026-10-03');
        $latest = $this->dayPlans->carryForward('2026-10-04')->first();

        // Three slips, and every one of them is still on its own date.
        $this->assertSame(3, $this->dayPlans->slipCount($latest));
        $this->assertSame(4, DayPlanItem::query()->count());
    }

    #[Test]
    public function a_carried_item_is_attributed_to_the_system_not_to_a_person(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->dayPlans->create($enrollment, '2026-10-01', 'Unfinished', $this->admin());

        $carried = $this->dayPlans->carryForward('2026-10-02')->first();

        // Nobody planned this; the sweep moved it.
        $this->assertSame(ActorSource::System, $carried->source);
        $this->assertNull($carried->created_by);
        $this->assertNull($carried->access_grant_id);
    }

    #[Test]
    public function carry_forward_never_crosses_participants(): void
    {
        $mine = Enrollment::factory()->create();
        $theirs = Enrollment::factory()->create();
        $this->dayPlans->create($mine, '2026-10-01', 'Mine', $this->admin());
        $this->dayPlans->create($theirs, '2026-10-01', 'Theirs', $this->admin());

        $created = $this->dayPlans->carryForward('2026-10-02', $mine);

        $this->assertCount(1, $created);
        $this->assertSame($mine->getKey(), $created->first()->enrollment_id);
        $this->assertSame(DayPlanItem::STATUS_PLANNED, DayPlanItem::query()
            ->where('enrollment_id', $theirs->getKey())->first()->status);
    }

    #[Test]
    public function the_carry_chain_must_point_at_an_earlier_date_for_the_same_participant(): void
    {
        // Invariant I14.
        $enrollment = Enrollment::factory()->create();
        $earlier = DayPlanItem::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $enrollment->customer_id,
            'plan_date' => '2026-10-05',
        ]);
        $later = DayPlanItem::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $enrollment->customer_id,
            'plan_date' => '2026-10-01',
            'carried_from_id' => $earlier->getKey(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not earlier than its own date');

        $this->dayPlans->assertCarryChainIsCoherent($later);
    }

    #[Test]
    public function a_carry_chain_across_participants_is_refused(): void
    {
        $mine = Enrollment::factory()->create();
        $theirs = Enrollment::factory()->create();

        $origin = DayPlanItem::factory()->create([
            'enrollment_id' => $theirs->getKey(),
            'customer_id' => $theirs->customer_id,
            'plan_date' => '2026-10-01',
        ]);
        $item = DayPlanItem::factory()->create([
            'enrollment_id' => $mine->getKey(),
            'customer_id' => $mine->customer_id,
            'plan_date' => '2026-10-02',
            'carried_from_id' => $origin->getKey(),
        ]);

        $this->expectException(CustomerIsolationException::class);
        $this->dayPlans->assertCarryChainIsCoherent($item);
    }

    // --- Isolation -----------------------------------------------------------

    #[Test]
    public function the_redundant_customer_id_comes_from_the_enrolment(): void
    {
        $enrollment = Enrollment::factory()->create();

        $item = $this->dayPlans->create($enrollment, '2026-10-01', 'Task', $this->admin());

        $this->assertSame((int) $enrollment->customer_id, (int) $item->customer_id);
    }

    #[Test]
    public function the_customer_on_an_item_is_never_reassigned(): void
    {
        $item = DayPlanItem::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $item->forceFill(['customer_id' => Customer::factory()->create()->getKey()])->save();
    }

    #[Test]
    public function a_grant_for_another_participant_cannot_write_here(): void
    {
        $enrollment = Enrollment::factory()->create();
        $foreignGrant = AccessGrant::factory()->create(['ability' => GrantAbility::EnterDayPlan]);

        $this->expectException(CustomerIsolationException::class);

        $this->dayPlans->create($enrollment, '2026-10-01', 'Not mine', grant: $foreignGrant);
    }

    #[Test]
    public function a_participant_can_enter_their_own_plan_through_a_link(): void
    {
        $enrollment = Enrollment::factory()->create();
        $grant = AccessGrant::factory()->create([
            'customer_id' => $enrollment->customer_id,
            'enrollment_id' => $enrollment->getKey(),
            'ability' => GrantAbility::EnterDayPlan,
        ]);

        $item = $this->dayPlans->create($enrollment, '2026-10-01', 'Mine', grant: $grant);

        $this->assertSame(ActorSource::ExternalGrant, $item->source);
        $this->assertSame($grant->getKey(), $item->access_grant_id);
        $this->assertNull($item->created_by);
    }

    #[Test]
    public function customer_a_cannot_read_customer_b_day_plans(): void
    {
        $mine = DayPlanItem::factory()->create();
        DayPlanItem::factory()->create();

        $found = DayPlanItem::query()->forCustomer((int) $mine->customer_id)->get();

        $this->assertCount(1, $found);
        $this->assertSame($mine->getKey(), $found->first()->getKey());
    }

    #[Test]
    public function a_day_plan_item_is_never_deleted(): void
    {
        $item = DayPlanItem::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never deleted');

        $item->delete();
    }

    // --- [CLIENT DECISION - S1] ----------------------------------------------

    #[Test]
    public function the_three_unlabelled_columns_are_carried_exactly_as_supplied(): void
    {
        // [CLIENT DECISION - S1]. The COUNT is settled at three; the labels
        // are not. They are never expanded, renamed or guessed at.
        foreach (['g', 'c', 'm'] as $column) {
            $this->assertTrue(Schema::hasColumn('day_plan_items', $column));
        }

        $this->assertSame(['g', 'c', 'm'], DayPlanItem::UNLABELLED_COLUMNS);

        foreach ([
            'goal', 'growth', 'category', 'client', 'cost', 'money', 'minutes', 'meeting',
            'g_label', 'c_label', 'm_label',
        ] as $invented) {
            $this->assertFalse(
                Schema::hasColumn('day_plan_items', $invented),
                "[{$invented}] would be a guess at what G, C or M stand for."
            );
        }
    }

    #[Test]
    public function the_three_columns_round_trip_whatever_is_put_in_them(): void
    {
        $item = $this->dayPlans->create(
            Enrollment::factory()->create(), '2026-10-01', 'Task', $this->admin(),
            g: 'g-value', c: 'c-value', m: 'm-value',
        );

        $this->assertSame('g-value', $item->g);
        $this->assertSame('c-value', $item->c);
        $this->assertSame('m-value', $item->m);
    }
}
