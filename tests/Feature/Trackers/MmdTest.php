<?php

declare(strict_types=1);

namespace Tests\Feature\Trackers;

use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\MmdTargetService;
use App\Domain\Trackers\TargetVsActualCalculator;
use App\Domain\Trackers\WeeklyReviewProjector;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The daily business figures, their targets, and the views derived from both.
 *
 * Nothing computed is stored. Target-versus-actual and the T/Th/S weekly view
 * are queries, so an amended figure changes every conclusion drawn from it.
 */
class MmdTest extends TestCase
{
    use RefreshDatabase;

    private MmdEntryService $entries;

    private MmdTargetService $targets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->entries = app(MmdEntryService::class);
        $this->targets = app(MmdTargetService::class);
    }

    // --- The measure set -----------------------------------------------------

    #[Test]
    public function the_measure_set_is_the_confirmed_vocabulary(): void
    {
        $this->assertSame([
            'fund_in',
            'fund_out',
            'enquiries_new',
            'enquiries_repeat',
            'enquiries_referral',
            'sales_closed_count',
            'sales_closed_value',
            'production',
        ], MmdEntry::METRICS);
    }

    #[Test]
    public function every_metric_is_recordable(): void
    {
        $customer = Customer::factory()->create();

        $entry = $this->entries->record($customer, '2026-10-01', [
            'fund_in' => 12000,
            'fund_out' => 4500,
            'enquiries_new' => 12,
            'enquiries_repeat' => 4,
            'enquiries_referral' => 3,
            'sales_closed_count' => 6,
            'sales_closed_value' => 88000,
            'production' => 51000,
        ], actor: $this->admin());

        $this->assertSame('12000.00', $entry->fund_in);
        $this->assertSame('4500.00', $entry->fund_out);
        $this->assertSame(12, $entry->enquiries_new);
        $this->assertSame(4, $entry->enquiries_repeat);
        $this->assertSame(3, $entry->enquiries_referral);
        $this->assertSame(6, $entry->sales_closed_count);
        $this->assertSame('88000.00', $entry->sales_closed_value);
        $this->assertSame('51000.00', $entry->production);
    }

    #[Test]
    public function an_unknown_metric_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown MMD metric');

        $this->entries->record(Customer::factory()->create(), '2026-10-01', ['footfall' => 12]);
    }

    #[Test]
    public function a_negative_figure_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->entries->record(Customer::factory()->create(), '2026-10-01', ['fund_in' => -1]);
    }

    #[Test]
    public function t_th_s_add_no_columns(): void
    {
        // Tuesday / Thursday / Saturday are a DERIVED weekly presentation, not
        // measures. A weekday column would have to be kept in step with
        // entry_date, and the two would eventually disagree.
        foreach (['weekday', 'day_of_week', 'review_day', 'is_tuesday', 't', 'th', 's'] as $column) {
            $this->assertFalse(Schema::hasColumn('mmd_entries', $column));
        }
    }

    #[Test]
    public function there_is_no_dashboard_table(): void
    {
        // Target-versus-actual, the weekly view and the evaluation row are all
        // queries.
        foreach (['mmd_dashboard', 'mmd_dashboards', 'mmd_summaries', 'mmd_analytics'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    // --- Ownership and attribution --------------------------------------------

    #[Test]
    public function an_entry_is_owned_by_the_business_and_merely_attributed_to_a_run(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $entry = $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], $enrollment, $this->admin());

        $this->assertSame($customer->getKey(), $entry->customer_id);
        $this->assertSame($enrollment->getKey(), $entry->enrollment_id);
    }

    #[Test]
    public function business_figures_survive_the_run_they_were_attributed_to(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $entry = $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], $enrollment);

        // SET NULL, not RESTRICT and not CASCADE: attribution is optional and
        // the figures outlive it.
        $fk = collect(Schema::getForeignKeys('mmd_entries'))
            ->first(fn (array $f): bool => $f['columns'] === ['enrollment_id']);

        $this->assertSame('set null', strtolower((string) $fk['on_delete']));
        $this->assertNotNull($entry->fresh());
    }

    #[Test]
    public function an_enrolment_from_another_business_cannot_be_attributed(): void
    {
        $customer = Customer::factory()->create();
        $foreign = Enrollment::factory()->create();

        $this->expectException(CustomerIsolationException::class);

        $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], $foreign);
    }

    #[Test]
    public function customer_a_cannot_read_customer_b_figures(): void
    {
        $mine = MmdEntry::factory()->create();
        MmdEntry::factory()->create();

        $found = MmdEntry::query()->forCustomer((int) $mine->customer_id)->get();

        $this->assertCount(1, $found);
        $this->assertSame($mine->getKey(), $found->first()->getKey());
    }

    #[Test]
    public function an_entry_may_be_filed_through_a_link(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $grant = AccessGrant::factory()->create([
            'customer_id' => $customer->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'ability' => GrantAbility::EnterMmd,
        ]);

        $entry = $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], $enrollment, grant: $grant);

        $this->assertSame(ActorSource::ExternalGrant, $entry->source);
        $this->assertSame($grant->getKey(), $entry->access_grant_id);
        $this->assertNull($entry->created_by);
    }

    // --- Amendment and optimistic locking --------------------------------------

    #[Test]
    public function amending_records_what_the_figure_was(): void
    {
        $customer = Customer::factory()->create();
        $entry = $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], actor: $this->admin());

        $amended = $this->entries->amend($entry, ['fund_in' => 250], 0, $this->admin(), 'Bank statement');

        $this->assertSame('250.00', $amended->fund_in);
        $this->assertTrue($amended->wasAmended());
        $this->assertSame(1, $amended->lock_version);

        $log = AuditLog::query()->where('action', AuditAction::MmdEntryAmended)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('100.00', $log->old_values['fund_in']);
    }

    #[Test]
    public function a_concurrent_write_is_rejected_rather_than_silently_overwriting(): void
    {
        // Two actors plausibly write one row: the owner through an enter_mmd
        // link while staff key the same day from a paper sheet.
        //
        // The mechanism is a conditional UPDATE on lock_version, chosen
        // precisely because lockForUpdate() is a no-op on SQLite and would
        // pass here while failing in production. A conditional UPDATE behaves
        // identically on both engines, so this test proves the real mechanism.
        $customer = Customer::factory()->create();
        $entry = $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], actor: $this->admin());

        // Staff read version 0 and are still typing.
        $staffSawVersion = 0;

        // The owner's link writes first.
        $this->entries->amend($entry->fresh(), ['fund_in' => 500], 0, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed since it was read');

        $this->entries->amend($entry->fresh(), ['fund_in' => 200], $staffSawVersion, $this->admin());
    }

    #[Test]
    public function the_losing_write_changes_nothing(): void
    {
        $customer = Customer::factory()->create();
        $entry = $this->entries->record($customer, '2026-10-01', ['fund_in' => 100], actor: $this->admin());
        $this->entries->amend($entry->fresh(), ['fund_in' => 500], 0, $this->admin());

        try {
            $this->entries->amend($entry->fresh(), ['fund_in' => 200], 0, $this->admin());
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('500.00', $entry->fresh()->fund_in);
    }

    #[Test]
    public function lock_version_exists_on_this_table_and_no_other_tracker(): void
    {
        $this->assertTrue(Schema::hasColumn('mmd_entries', 'lock_version'));

        foreach (['day_plan_items', 'time_grid_entries', 'mmd_targets', 'fund_plans', 'fund_plan_lines', 'action_items'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'lock_version'),
                "[{$table}] has no concrete contention scenario and must not carry a lock column."
            );
        }
    }

    #[Test]
    public function an_entry_is_never_deleted(): void
    {
        $entry = MmdEntry::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never deleted');

        $entry->delete();
    }

    // --- Targets ---------------------------------------------------------------

    #[Test]
    public function a_target_is_set_for_a_metric_over_a_period(): void
    {
        $enrollment = Enrollment::factory()->create();

        $target = $this->targets->set(
            $enrollment, 'sales_closed_value', MmdTarget::PERIOD_MONTHLY,
            '2026-10-01', '2026-10-31', 500000, $this->admin(),
        );

        $this->assertSame('sales_closed_value', $target->metric);
        $this->assertSame('500000.00', $target->target_value);
        $this->assertSame($enrollment->getKey(), $target->enrollment_id);
    }

    #[Test]
    public function targets_mirror_the_recorded_measure_set(): void
    {
        $enrollment = Enrollment::factory()->create();

        foreach (MmdEntry::METRICS as $metric) {
            $target = $this->targets->set(
                $enrollment, $metric, MmdTarget::PERIOD_MONTHLY,
                '2026-10-01', '2026-10-31', 100, $this->admin(),
            );

            $this->assertSame($metric, $target->metric);
        }
    }

    #[Test]
    public function a_target_cannot_be_set_for_something_never_recorded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->targets->set(
            Enrollment::factory()->create(), 'footfall', MmdTarget::PERIOD_MONTHLY,
            '2026-10-01', '2026-10-31', 100, $this->admin(),
        );
    }

    #[Test]
    public function revising_a_target_changes_the_row_and_is_audited(): void
    {
        $enrollment = Enrollment::factory()->create();
        $actor = $this->admin();

        $this->targets->set($enrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 100, $actor);
        $revised = $this->targets->set($enrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 250, $actor);

        // One target per metric per period: two would make every comparison
        // against it ambiguous.
        $this->assertSame(1, MmdTarget::query()->count());
        $this->assertSame('250.00', $revised->target_value);

        $log = AuditLog::query()->where('action', AuditAction::MmdTargetSet)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('100.00', $log->old_values['target_value']);
    }

    #[Test]
    public function targets_and_actuals_live_apart(): void
    {
        // A target is an intention; an entry is what happened. Neither table
        // carries the other's column.
        foreach (['fund_in', 'sales_closed_value', 'production', 'entry_date'] as $column) {
            $this->assertFalse(Schema::hasColumn('mmd_targets', $column));
        }

        foreach (['target_value', 'period_type', 'period_start'] as $column) {
            $this->assertFalse(Schema::hasColumn('mmd_entries', $column));
        }
    }

    #[Test]
    public function a_target_has_no_actor_triple_because_setting_one_is_internal(): void
    {
        foreach (['source', 'created_by', 'access_grant_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('mmd_targets', $column));
        }

        $this->assertTrue(Schema::hasColumn('mmd_targets', 'set_by'));
    }

    // --- Target versus actual ---------------------------------------------------

    #[Test]
    public function target_versus_actual_is_derived_from_the_entries(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $this->entries->record($customer, '2026-10-05', ['sales_closed_value' => 30000], $enrollment);
        $this->entries->record($customer, '2026-10-12', ['sales_closed_value' => 45000], $enrollment);
        // Outside the period.
        $this->entries->record($customer, '2026-11-02', ['sales_closed_value' => 99000], $enrollment);

        $target = $this->targets->set(
            $enrollment, 'sales_closed_value', MmdTarget::PERIOD_MONTHLY,
            '2026-10-01', '2026-10-31', 100000, $this->admin(),
        );

        $comparison = app(TargetVsActualCalculator::class)->compare($target, $customer);

        $this->assertSame(100000.0, $comparison['target']);
        $this->assertSame(75000.0, $comparison['actual']);
        $this->assertSame(-25000.0, $comparison['variance']);
    }

    #[Test]
    public function no_comparison_is_stored_anywhere(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $this->entries->record($customer, '2026-10-05', ['production' => 100], $enrollment);
        $target = $this->targets->set(
            $enrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 500, $this->admin(),
        );

        $calculator = app(TargetVsActualCalculator::class);
        $before = $calculator->compare($target, $customer);

        // Amending the figure changes the conclusion, which is only possible
        // because nothing was cached.
        $entry = MmdEntry::query()->where('customer_id', $customer->getKey())->first();
        $this->entries->amend($entry, ['production' => 400], (int) $entry->lock_version, $this->admin());

        $after = $calculator->compare($target->fresh(), $customer);

        $this->assertSame(100.0, $before['actual']);
        $this->assertSame(400.0, $after['actual']);
    }

    #[Test]
    public function nothing_recorded_is_not_the_same_as_zero(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $target = $this->targets->set(
            $enrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 500, $this->admin(),
        );

        $comparison = app(TargetVsActualCalculator::class)->compare($target, $customer);

        $this->assertNull($comparison['actual']);
        $this->assertNull($comparison['variance']);
    }

    #[Test]
    public function the_scorecard_never_reaches_another_business(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $mine->getKey()]);

        $this->entries->record($mine, '2026-10-05', ['production' => 100], $enrollment);
        $this->entries->record($theirs, '2026-10-05', ['production' => 9999]);

        $this->targets->set($enrollment, 'production', MmdTarget::PERIOD_MONTHLY, '2026-10-01', '2026-10-31', 500, $this->admin());

        $scorecard = app(TargetVsActualCalculator::class)->scorecard($enrollment->fresh());

        $this->assertCount(1, $scorecard);
        $this->assertSame(100.0, $scorecard[0]['actual']);
    }

    // --- T / Th / S ---------------------------------------------------------------

    #[Test]
    public function the_weekly_review_is_derived_from_daily_rows(): void
    {
        $customer = Customer::factory()->create();

        // 2026-10-06 Tue, 2026-10-08 Thu, 2026-10-10 Sat.
        $this->entries->record($customer, '2026-10-06', ['fund_in' => 100]);
        $this->entries->record($customer, '2026-10-08', ['fund_in' => 200]);
        $this->entries->record($customer, '2026-10-10', ['fund_in' => 300]);

        $week = app(WeeklyReviewProjector::class)->week($customer, '2026-10-07');

        $this->assertSame(['T', 'Th', 'S'], array_keys($week));
        $this->assertSame(100.0, $week['T']['fund_in']);
        $this->assertSame(200.0, $week['Th']['fund_in']);
        $this->assertSame(300.0, $week['S']['fund_in']);
    }

    #[Test]
    public function the_review_days_are_tuesday_thursday_saturday(): void
    {
        $this->assertSame(['T' => 2, 'Th' => 4, 'S' => 6], WeeklyReviewProjector::REVIEW_DAYS);
    }

    #[Test]
    public function the_projector_reports_presence_not_compliance(): void
    {
        // Whether an absence is a MISS depends on [L6], which is unanswered.
        $customer = Customer::factory()->create();
        $this->entries->record($customer, '2026-10-06', ['fund_in' => 100]);

        $recorded = app(WeeklyReviewProjector::class)->recordedOn($customer, '2026-10-07');

        $this->assertTrue($recorded['T']);
        $this->assertFalse($recorded['Th']);
        $this->assertFalse($recorded['S']);
    }

    // --- [B1 CLIENT DECISION - MMD GRAIN] ------------------------------------------

    #[Test]
    public function no_grain_assumption_is_baked_into_the_schema(): void
    {
        // [B1 CLIENT DECISION - MMD GRAIN]. Answer A wants
        // UNIQUE (customer_id, entry_date); answer B wants that plus a
        // contributor dimension. Neither is applied, because this is a grain
        // and primary key question: the wrong choice means rebuilding the
        // table and every query and reminder that reads it.
        $unique = collect(Schema::getIndexes('mmd_entries'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertFalse($unique->contains(['customer_id', 'entry_date']));
    }

    #[Test]
    public function no_contributor_dimension_is_invented(): void
    {
        foreach ([
            'contributor', 'contributor_id', 'contributor_type', 'function',
            'business_function', 'department', 'entered_by_role', 'staff_id',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('mmd_entries', $column),
                "[{$column}] would silently answer B1 in favour of contributor rows."
            );
        }
    }

    #[Test]
    public function the_service_permits_both_grains(): void
    {
        $customer = Customer::factory()->create();

        // Under answer B this is two contributors filing for one day; under
        // answer A it is a duplicate the database will refuse once the key is
        // added. The service refuses neither, so answering B1 changes nothing
        // here.
        $this->entries->record($customer, '2026-10-01', ['fund_in' => 100]);
        $this->entries->record($customer, '2026-10-01', ['fund_in' => 250]);

        $this->assertCount(2, $this->entries->entriesFor($customer, '2026-10-01'));
    }

    #[Test]
    public function every_read_aggregates_so_it_is_correct_under_either_grain(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $this->entries->record($customer, '2026-10-06', ['fund_in' => 100], $enrollment);
        $this->entries->record($customer, '2026-10-06', ['fund_in' => 250], $enrollment);

        // One row summed is that row; several rows summed is the business
        // total. Both answers give the right figure.
        $actual = app(TargetVsActualCalculator::class)->actual($customer, 'fund_in', '2026-10-01', '2026-10-31');
        $week = app(WeeklyReviewProjector::class)->week($customer, '2026-10-06');

        $this->assertSame(350.0, $actual);
        $this->assertSame(350.0, $week['T']['fund_in']);
    }

    #[Test]
    public function the_presence_check_does_not_depend_on_the_grain(): void
    {
        // What notification triggers 5 and 6 need, phrased so B1 does not
        // change the answer.
        $customer = Customer::factory()->create();

        $this->assertFalse($this->entries->hasEntryFor($customer, '2026-10-01'));
        $this->entries->record($customer, '2026-10-01', ['fund_in' => 100]);
        $this->assertTrue($this->entries->hasEntryFor($customer, '2026-10-01'));
    }

    #[Test]
    #[Group('client-decision')]
    public function the_grain_constraint_matches_the_clients_answer(): void
    {
        $this->markTestSkipped(
            '[B1 CLIENT DECISION - MMD GRAIN] Unresolved: whether one business files one MMD row '
            .'per day (answer A, UNIQUE (customer_id, entry_date)) or its contributors file their '
            .'own (answer B, that key plus a contributor dimension). This is a grain and primary '
            .'key question, not an additive column, so neither is applied and no contributor '
            .'column is invented.'
        );
    }
}
