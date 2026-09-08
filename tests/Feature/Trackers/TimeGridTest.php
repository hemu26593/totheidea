<?php

declare(strict_types=1);

namespace Tests\Feature\Trackers;

use App\Domain\Trackers\TimeGridService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\TimeGridEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Q1-Q4 strategic allocation.
 *
 * The most important assertions in this file are the negative ones: the Time
 * Grid is NOT the Day Plan, shares no storage with it, and never acquires a
 * date, a status or a carry-forward.
 */
class TimeGridTest extends TestCase
{
    use RefreshDatabase;

    private TimeGridService $timeGrid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->timeGrid = app(TimeGridService::class);
    }

    // --- Q1 / Q2 / Q3 / Q4 ---------------------------------------------------

    #[Test]
    public function all_four_quarters_are_recordable(): void
    {
        $enrollment = Enrollment::factory()->create();

        foreach (TimeGridEntry::QUARTERS as $quarter) {
            $entry = $this->timeGrid->record(
                $enrollment, 2026, $quarter, "Activity Q{$quarter}", 100.0, null, $this->admin(),
            );

            $this->assertSame($quarter, $entry->quarter);
            $this->assertSame("Q{$quarter}", $entry->quarterLabel());
        }

        $this->assertCount(4, $this->timeGrid->gridFor($enrollment, 2026));
    }

    #[Test]
    public function the_quarters_are_exactly_four(): void
    {
        $this->assertSame([1, 2, 3, 4], TimeGridEntry::QUARTERS);
    }

    #[Test]
    public function there_is_no_fifth_quarter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Q1, Q2, Q3 or Q4');

        $this->timeGrid->record(Enrollment::factory()->create(), 2026, 5, 'Nope', 10.0);
    }

    #[Test]
    public function a_quarter_returns_only_its_own_activities(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->timeGrid->record($enrollment, 2026, 1, 'Selling', 120.0);
        $this->timeGrid->record($enrollment, 2026, 2, 'Recruiting', 80.0);

        $q1 = $this->timeGrid->quarter($enrollment, 2026, 1);

        $this->assertCount(1, $q1);
        $this->assertSame('Selling', $q1->first()->activity);
    }

    // --- Validation ----------------------------------------------------------

    #[Test]
    public function one_activity_cannot_be_recorded_twice_in_one_quarter(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->timeGrid->record($enrollment, 2026, 1, 'Selling', 120.0);

        // Two rows for the same activity would double-count planned hours.
        $this->expectException(QueryException::class);
        $this->timeGrid->record($enrollment, 2026, 1, 'Selling', 40.0);
    }

    #[Test]
    public function hours_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->timeGrid->record(Enrollment::factory()->create(), 2026, 1, 'Selling', -5.0);
    }

    #[Test]
    public function actuals_arrive_after_planning_and_the_amendment_is_audited(): void
    {
        $enrollment = Enrollment::factory()->create();
        $entry = $this->timeGrid->record($enrollment, 2026, 1, 'Selling', 120.0, null, $this->admin());

        $amended = $this->timeGrid->amend($entry, null, 96.5, $this->admin());

        $this->assertSame('120.00', $amended->planned_hours);
        $this->assertSame('96.50', $amended->actual_hours);

        $log = AuditLog::query()->where('action', AuditAction::TimeGridAmended)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('96.50', $log->new_values['actual_hours']);
    }

    // --- Distinct from the Day Plan -------------------------------------------

    #[Test]
    public function the_time_grid_and_the_day_plan_share_no_storage(): void
    {
        $this->assertNotSame('day_plan_items', (new TimeGridEntry)->getTable());
        $this->assertSame('time_grid_entries', (new TimeGridEntry)->getTable());
    }

    #[Test]
    public function the_time_grid_has_no_daily_execution_columns(): void
    {
        // Strategic allocation, not a task list. If any of these ever appears
        // here, the two instruments have been merged.
        foreach ([
            'plan_date', 'task', 'status', 'planned_start', 'planned_end',
            'carried_from_id', 'actual_minutes', 'completed_at', 'due_date',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('time_grid_entries', $column),
                "time_grid_entries must not have [{$column}] - that belongs to the Day Plan."
            );
        }
    }

    #[Test]
    public function the_day_plan_has_no_quarterly_allocation_columns(): void
    {
        foreach (['quarter', 'year', 'planned_hours', 'actual_hours'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('day_plan_items', $column),
                "day_plan_items must not have [{$column}] - that belongs to the Time Grid."
            );
        }
    }

    #[Test]
    public function no_allocation_score_or_formula_is_stored(): void
    {
        // Planned and actual hours as entered. Nothing scores a quarter or
        // ranks activities, because no such rule was specified.
        foreach (['score', 'rating', 'allocation_percent', 'variance', 'band', 'weight'] as $column) {
            $this->assertFalse(Schema::hasColumn('time_grid_entries', $column));
        }
    }

    // --- Isolation -------------------------------------------------------------

    #[Test]
    public function a_grant_for_another_participant_cannot_write_a_grid_entry(): void
    {
        $enrollment = Enrollment::factory()->create();
        $foreign = AccessGrant::factory()->create(['ability' => GrantAbility::EnterDayPlan]);

        $this->expectException(CustomerIsolationException::class);

        $this->timeGrid->record($enrollment, 2026, 1, 'Not mine', 10.0, null, null, $foreign);
    }

    #[Test]
    public function a_grid_belongs_to_one_participant_only(): void
    {
        $mine = Enrollment::factory()->create();
        $theirs = Enrollment::factory()->create();

        $this->timeGrid->record($mine, 2026, 1, 'Selling', 120.0);
        $this->timeGrid->record($theirs, 2026, 1, 'Selling', 200.0);

        $grid = $this->timeGrid->gridFor($mine, 2026);

        $this->assertCount(1, $grid);
        $this->assertSame($mine->getKey(), $grid->first()->enrollment_id);
    }

    #[Test]
    public function an_entry_records_who_entered_it(): void
    {
        $actor = $this->admin();
        $entry = $this->timeGrid->record(
            Enrollment::factory()->create(), 2026, 1, 'Selling', 120.0, null, $actor,
        );

        $this->assertSame(ActorSource::InternalUser, $entry->source);
        $this->assertSame($actor->getKey(), $entry->created_by);
    }
}
