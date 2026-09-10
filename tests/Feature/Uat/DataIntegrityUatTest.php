<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\NotificationDispatchService;
use App\Livewire\Customers\ActionPlan;
use App\Livewire\Customers\DayPlan;
use App\Livewire\Customers\Mmd;
use App\Livewire\Customers\Notes as NotesScreen;
use App\Livewire\Customers\TimeGrid;
use App\Models\ActionItem;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use App\Models\NotificationDispatch;
use App\Models\Program;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT section 22: a data-integrity sweep over what the application itself
 * writes.
 *
 * The sweep is deliberately schema-driven rather than a hand-written list of
 * tables. A future table that carries a customer_id is caught by these tests
 * on the day it is added, which a list of names would not be.
 */
class DataIntegrityUatTest extends TestCase
{
    use RefreshDatabase;

    private Customer $alpha;

    private Customer $beta;

    private Enrollment $alphaEnrollment;

    private Enrollment $betaEnrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();

        $program = Program::factory()->create(['session_count' => 6]);

        $this->alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $this->beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        $this->alphaEnrollment = Enrollment::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'batch_id' => Batch::factory()->create(['program_id' => $program->getKey()])->getKey(),
        ]);

        $this->betaEnrollment = Enrollment::factory()->create([
            'customer_id' => $this->beta->getKey(),
            'batch_id' => Batch::factory()->create(['program_id' => $program->getKey()])->getKey(),
        ]);
    }

    /**
     * Drive the trackers through their own screens, so the rows swept below are
     * rows the application wrote rather than rows a factory invented.
     */
    private function workADayForBothCustomers(): void
    {
        $admin = $this->admin();

        foreach ([
            [$this->alpha, $this->alphaEnrollment],
            [$this->beta, $this->betaEnrollment],
        ] as [$customer, $enrollment]) {
            Livewire::actingAs($admin)
                ->test(DayPlan::class, ['customer' => $customer])
                ->set('enrollmentId', $enrollment->getKey())
                ->set('date', now()->toDateString())
                ->call('startAdding')
                ->set('task', 'Walk the floor')
                ->set('plannedStart', '09:00')
                ->set('plannedEnd', '10:00')
                ->call('add')
                ->assertHasNoErrors();

            Livewire::actingAs($admin)
                ->test(TimeGrid::class, ['customer' => $customer])
                ->set('enrollmentId', $enrollment->getKey())
                ->call('startAdding', 1)
                ->set('activity', 'Production review')
                ->set('plannedHours', 12)
                ->set('actualHours', 9)
                ->call('save')
                ->assertHasNoErrors();

            Livewire::actingAs($admin)
                ->test(ActionPlan::class, ['customer' => $customer])
                ->set('enrollmentId', $enrollment->getKey())
                ->call('startAdding')
                ->set('title', 'Publish the dispatch SOP')
                ->call('add')
                ->assertHasNoErrors();

            Livewire::actingAs($admin)
                ->test(Mmd::class, ['customer' => $customer])
                ->call('startRecording')
                ->set('date', now()->toDateString())
                ->set('figures.fund_in', 4200)
                ->call('record')
                ->assertHasNoErrors();

            Livewire::actingAs($admin)
                ->test(NotesScreen::class, ['customer' => $customer])
                ->call('startNote')
                ->set('body', 'Reviewed the shift handover.')
                ->call('save')
                ->assertHasNoErrors();
        }
    }

    /**
     * Every table in the schema that carries the given column.
     *
     * @return array<int, string>
     */
    private function tablesWithColumn(string $column): array
    {
        $tables = [];

        // Laravel's own introspection, not sqlite_master: the same sweep has to
        // run against MySQL (ADR-006), and a catalogue query written for one
        // engine turns a portability guard into a SQLite-only one.
        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];

            if (str_contains($name, '.')) {
                $name = substr($name, strrpos($name, '.') + 1);
            }

            if (in_array($name, ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true)) {
                continue;
            }

            foreach (Schema::getColumns($name) as $definition) {
                if ((string) $definition['name'] === $column) {
                    $tables[] = $name;
                    break;
                }
            }
        }

        sort($tables);

        return $tables;
    }

    /*
    |--------------------------------------------------------------------------
    | Orphans and dangling references
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function no_row_anywhere_points_at_a_customer_that_does_not_exist(): void
    {
        $this->workADayForBothCustomers();

        $tables = $this->tablesWithColumn('customer_id');
        $this->assertNotEmpty($tables, 'The sweep found no customer-owned tables, which cannot be right.');

        foreach ($tables as $table) {
            $orphans = DB::table($table)
                ->whereNotNull("{$table}.customer_id")
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('customers')
                    ->whereColumn('customers.id', "{$table}.customer_id"))
                ->count();

            $this->assertSame(0, $orphans, "[{$table}] holds rows owned by a customer that does not exist.");
        }
    }

    #[Test]
    public function no_row_anywhere_points_at_an_enrollment_that_does_not_exist(): void
    {
        $this->workADayForBothCustomers();

        foreach ($this->tablesWithColumn('enrollment_id') as $table) {
            if ($table === 'enrollments') {
                continue;
            }

            $orphans = DB::table($table)
                ->whereNotNull("{$table}.enrollment_id")
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('enrollments')
                    ->whereColumn('enrollments.id', "{$table}.enrollment_id"))
                ->count();

            $this->assertSame(0, $orphans, "[{$table}] holds rows attached to an enrolment that does not exist.");
        }
    }

    #[Test]
    public function where_a_row_carries_both_owners_they_agree_with_each_other(): void
    {
        $this->workADayForBothCustomers();

        $both = array_values(array_intersect(
            $this->tablesWithColumn('customer_id'),
            $this->tablesWithColumn('enrollment_id'),
        ));

        $this->assertNotEmpty($both, 'No table carries both owners, which the schema says is not the case.');

        foreach ($both as $table) {
            if ($table === 'enrollments') {
                continue;
            }

            $mismatched = DB::table($table)
                ->join('enrollments', 'enrollments.id', '=', "{$table}.enrollment_id")
                ->whereNotNull("{$table}.customer_id")
                ->whereNotNull("{$table}.enrollment_id")
                ->whereColumn('enrollments.customer_id', '!=', "{$table}.customer_id")
                ->count();

            $this->assertSame(
                0,
                $mismatched,
                "[{$table}] holds a row whose customer_id contradicts the customer of its enrolment. "
                .'A denormalised owner that disagrees with the authoritative one is the shape a leak takes.',
            );
        }
    }

    #[Test]
    public function every_enrollment_points_at_a_customer_and_a_batch_that_exist(): void
    {
        $this->workADayForBothCustomers();

        $this->assertSame(0, Enrollment::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('customers')->whereColumn('customers.id', 'enrollments.customer_id'))
            ->count());

        $this->assertSame(0, Enrollment::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('batches')->whereColumn('batches.id', 'enrollments.batch_id'))
            ->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_cannot_be_enrolled_into_the_same_batch_twice(): void
    {
        $duplicates = DB::table('enrollments')
            ->select('customer_id', 'batch_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('customer_id', 'batch_id')
            ->get()
            ->filter(fn ($row): bool => (int) $row->n > 1);

        $this->assertCount(0, $duplicates);

        // And the database, not only the service, refuses the second one.
        $this->expectException(QueryException::class);

        Enrollment::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'batch_id' => $this->alphaEnrollment->batch_id,
        ]);
    }

    #[Test]
    public function a_customer_has_at_most_one_live_primary_contact(): void
    {
        CustomerContact::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'is_primary' => true,
            'archived_at' => null,
        ]);

        $offenders = DB::table('customer_contacts')
            ->select('customer_id', DB::raw('COUNT(*) AS n'))
            ->where('is_primary', true)
            ->whereNull('archived_at')
            ->groupBy('customer_id')
            ->get()
            ->filter(fn ($row): bool => (int) $row->n > 1);

        $this->assertCount(
            0,
            $offenders,
            'Two live primary contacts on one customer means the "primary contact" shown on screen is arbitrary.',
        );
    }

    #[Test]
    public function the_same_notification_is_never_dispatched_twice(): void
    {
        $contact = CustomerContact::factory()->create([
            'customer_id' => $this->alpha->getKey(),
            'is_primary' => true,
        ]);

        $service = app(NotificationDispatchService::class);

        $candidate = new NotificationCandidate(
            triggerKey: 'session_reminder',
            recipient: $contact,
            customerId: (int) $this->alpha->getKey(),
            enrollmentId: (int) $this->alphaEnrollment->getKey(),
            subject: $this->alphaEnrollment,
            scheduledFor: CarbonImmutable::now(),
            period: '2026-W37',
        );

        $first = $service->queue($candidate);
        $second = $service->queue($candidate);

        $this->assertNotNull($first, 'The first run must record the dispatch.');
        $this->assertNull($second, 'A repeat of the same candidate is recognised as already handled, not queued again.');
        $this->assertSame(1, NotificationDispatch::query()->count());

        $keys = DB::table('notification_dispatches')
            ->select('dedupe_key', DB::raw('COUNT(*) AS n'))
            ->groupBy('dedupe_key')
            ->get()
            ->filter(fn ($row): bool => (int) $row->n > 1);

        $this->assertCount(0, $keys);
    }

    /*
    |--------------------------------------------------------------------------
    | Nulls that would be silent bugs
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function nothing_the_application_wrote_landed_without_its_owner(): void
    {
        $this->workADayForBothCustomers();

        foreach ($this->tablesWithColumn('customer_id') as $table) {
            $definition = collect(Schema::getColumns($table))->firstWhere('name', 'customer_id');

            if ($definition !== null && $definition['nullable'] === false) {
                continue; // The schema itself forbids it.
            }

            // form_templates.customer_id is null by design - a shared template.
            // notification_dispatches may carry a system-level message.
            if (in_array($table, ['form_templates', 'notification_dispatches'], true)) {
                continue;
            }

            $this->assertSame(
                0,
                DB::table($table)->whereNull('customer_id')->count(),
                "[{$table}] holds rows with no owner, which no screen can scope.",
            );
        }
    }

    #[Test]
    public function the_two_customers_records_stayed_on_their_own_side_of_the_line(): void
    {
        $this->workADayForBothCustomers();

        foreach ([
            'day_plan_items' => 'customer_id',
            'mmd_entries' => 'customer_id',
        ] as $table => $column) {
            $alphaRows = DB::table($table)->where($column, $this->alpha->getKey())->count();
            $betaRows = DB::table($table)->where($column, $this->beta->getKey())->count();

            $this->assertSame(1, $alphaRows, "[{$table}] did not record Alpha's row exactly once.");
            $this->assertSame(1, $betaRows, "[{$table}] did not record Beta's row exactly once.");
        }

        // Enrolment-owned trackers: each customer's own enrolment, and no other.
        foreach (['time_grid_entries', 'action_items'] as $table) {
            $this->assertSame(
                [$this->alphaEnrollment->getKey(), $this->betaEnrollment->getKey()],
                DB::table($table)->orderBy('enrollment_id')->pluck('enrollment_id')->all(),
            );
        }

        $this->assertSame(
            [
                ['type' => Customer::class, 'id' => $this->alpha->getKey()],
                ['type' => Customer::class, 'id' => $this->beta->getKey()],
            ],
            DB::table('notes')->orderBy('notable_id')->get()
                ->map(fn ($n): array => ['type' => $n->notable_type, 'id' => (int) $n->notable_id])
                ->all(),
        );
    }

    #[Test]
    public function no_action_item_or_tracker_row_survives_pointing_at_a_stale_status(): void
    {
        $this->workADayForBothCustomers();

        $this->assertSame(
            [],
            ActionItem::query()
                ->whereNotIn('status', ActionItem::STATUSES)
                ->pluck('status')
                ->all(),
        );

        $this->assertSame(
            [],
            DB::table('day_plan_items')
                ->whereNotIn('status', DayPlanItem::STATUSES)
                ->pluck('status')
                ->all(),
        );
    }
}
