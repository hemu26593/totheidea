<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use App\Domain\Business\PositionService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The participant's own org chart.
 *
 * Two things carry the most weight here. First, holder_name stays a STRING:
 * the participant's staff are not platform users and a foreign key here would
 * manufacture the customer account the whole architecture is built to avoid.
 * Second, invariant I13 - a parent in the same business, and no cycles.
 */
class PositionTest extends TestCase
{
    use RefreshDatabase;

    private PositionService $positions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->positions = app(PositionService::class);
    }

    // --- Creation and shape ---------------------------------------------------

    #[Test]
    public function a_position_records_a_title_a_holder_a_role_and_its_kra(): void
    {
        $customer = Customer::factory()->create();

        $position = $this->positions->create(
            $customer,
            'Operations Manager',
            holderName: 'Priya Shah',
            roleDescription: 'Runs the delivery floor.',
            kra: 'On-time delivery; scrap below 2%.',
            actor: $this->admin(),
        );

        $this->assertSame('Operations Manager', $position->title);
        $this->assertSame('Priya Shah', $position->holder_name);
        $this->assertSame('Runs the delivery floor.', $position->role_description);
        $this->assertSame('On-time delivery; scrap below 2%.', $position->kra);
        $this->assertSame((int) $customer->getKey(), (int) $position->customer_id);
        $this->assertTrue($position->isRoot());
    }

    #[Test]
    public function a_position_needs_a_title(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->positions->create(Customer::factory()->create(), '   ');
    }

    #[Test]
    public function two_positions_may_share_a_title(): void
    {
        $customer = Customer::factory()->create();

        $this->positions->create($customer, 'Area Manager');
        $this->positions->create($customer, 'Area Manager');

        // Deliberately no unique key: two Area Managers is a normal org chart.
        $this->assertSame(2, Position::query()->where('customer_id', $customer->getKey())->count());
    }

    #[Test]
    public function a_position_is_updated_in_place_while_active(): void
    {
        $position = $this->positions->create(Customer::factory()->create(), 'Supervisor');

        $updated = $this->positions->update($position, [
            'title' => 'Shift Supervisor',
            'holder_name' => 'Ravi Patel',
        ]);

        $this->assertSame('Shift Supervisor', $updated->title);
        $this->assertSame('Ravi Patel', $updated->holder_name);
    }

    #[Test]
    public function an_archived_position_is_not_edited(): void
    {
        $position = Position::factory()->archived()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archived');

        $this->positions->update($position, ['title' => 'Something else']);
    }

    // --- The person is not a platform user ---------------------------------------

    #[Test]
    public function holder_name_is_a_string_and_there_is_no_foreign_key_to_users(): void
    {
        // A staff member of the participant's business has no account. A key
        // to users here would create exactly the customer login the
        // architecture forbids.
        $columns = collect(Schema::getColumns('positions'))->keyBy('name');

        $this->assertArrayHasKey('holder_name', $columns->all());
        $this->assertStringContainsString('varchar', strtolower((string) $columns['holder_name']['type']));

        $userFks = collect(Schema::getForeignKeys('positions'))
            ->filter(fn (array $fk): bool => $fk['foreign_table'] === 'users')
            ->flatMap(fn (array $fk): array => $fk['columns'])
            ->values()
            ->all();

        // created_by is the actor triple - who keyed the row into the
        // platform. It is NOT who holds the position.
        $this->assertSame(['created_by'], $userFks);
    }

    #[Test]
    public function positions_carry_no_credential_columns(): void
    {
        foreach ([
            'user_id', 'holder_user_id', 'email', 'password', 'remember_token',
            'is_active', 'last_login_at', 'role_id',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('positions', $column),
                "positions must not have [{$column}] - the participant's staff do not authenticate."
            );
        }
    }

    // --- Invariant I13, first half -------------------------------------------------

    #[Test]
    public function a_parent_from_another_business_is_refused(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $foreignParent = $this->positions->create($theirs, 'Their Director');

        $this->expectException(CustomerIsolationException::class);

        $this->positions->create($mine, 'My Manager', $foreignParent);
    }

    #[Test]
    public function reparenting_under_another_business_is_refused(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $position = $this->positions->create($mine, 'Manager');
        $foreignParent = $this->positions->create($theirs, 'Their Director');

        $this->expectException(CustomerIsolationException::class);

        $this->positions->reparent($position, $foreignParent);
    }

    // --- Invariant I13, second half: no cycles ---------------------------------------

    #[Test]
    public function a_position_cannot_report_to_itself(): void
    {
        $position = $this->positions->create(Customer::factory()->create(), 'Director');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot report to itself');

        $this->positions->reparent($position, $position);
    }

    #[Test]
    public function a_two_step_cycle_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $director = $this->positions->create($customer, 'Director');
        $manager = $this->positions->create($customer, 'Manager', $director);

        // Making the director report to their own report closes a loop.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would create a cycle');

        $this->positions->reparent($director, $manager);
    }

    #[Test]
    public function a_deep_cycle_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $a = $this->positions->create($customer, 'A');
        $b = $this->positions->create($customer, 'B', $a);
        $c = $this->positions->create($customer, 'C', $b);
        $d = $this->positions->create($customer, 'D', $c);

        $this->expectException(InvalidArgumentException::class);

        $this->positions->reparent($a, $d);
    }

    #[Test]
    public function a_legitimate_reparent_is_allowed(): void
    {
        $customer = Customer::factory()->create();
        $director = $this->positions->create($customer, 'Director');
        $otherDirector = $this->positions->create($customer, 'Second Director');
        $manager = $this->positions->create($customer, 'Manager', $director);

        $moved = $this->positions->reparent($manager, $otherDirector);

        $this->assertSame($otherDirector->getKey(), $moved->parent_position_id);
    }

    #[Test]
    public function a_position_can_be_moved_to_the_root(): void
    {
        $customer = Customer::factory()->create();
        $director = $this->positions->create($customer, 'Director');
        $manager = $this->positions->create($customer, 'Manager', $director);

        $moved = $this->positions->reparent($manager, null);

        $this->assertTrue($moved->isRoot());
    }

    #[Test]
    public function the_reporting_line_walks_to_the_root(): void
    {
        $customer = Customer::factory()->create();
        $a = $this->positions->create($customer, 'Owner');
        $b = $this->positions->create($customer, 'Director', $a);
        $c = $this->positions->create($customer, 'Manager', $b);

        $line = $this->positions->reportingLine($c->fresh());

        $this->assertSame(['Manager', 'Director', 'Owner'], array_map(
            fn (Position $p): string => $p->title,
            $line,
        ));
    }

    // --- Archive-only -----------------------------------------------------------------

    #[Test]
    public function removing_a_manager_does_not_remove_the_reports(): void
    {
        // SET NULL on parent_position_id: the reports become unparented, never
        // deleted.
        $fk = collect(Schema::getForeignKeys('positions'))
            ->first(fn (array $f): bool => $f['columns'] === ['parent_position_id']);

        $this->assertSame('set null', strtolower((string) $fk['on_delete']));
    }

    #[Test]
    public function a_position_is_never_deleted(): void
    {
        $position = Position::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never deleted');

        $position->delete();
    }

    #[Test]
    public function archiving_takes_a_position_out_of_the_chart_without_erasing_it(): void
    {
        $customer = Customer::factory()->create();
        $kept = $this->positions->create($customer, 'Kept');
        $retired = $this->positions->create($customer, 'Retired');

        $this->positions->archive($retired, $this->admin());

        $chart = $this->positions->chartFor($customer);

        $this->assertCount(1, $chart);
        $this->assertSame($kept->getKey(), $chart->first()->getKey());
        $this->assertNotNull($retired->fresh());
    }

    #[Test]
    public function an_archived_position_cannot_be_reported_to(): void
    {
        $customer = Customer::factory()->create();
        $retired = Position::factory()->archived()->create(['customer_id' => $customer->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be reported to');

        $this->positions->create($customer, 'New Manager', $retired);
    }

    // --- Ownership and the actor triple ----------------------------------------------

    #[Test]
    public function the_chart_belongs_to_the_business_not_to_a_programme_run(): void
    {
        // Owned by the customer directly: an org chart outlives an enrolment.
        $this->assertTrue(Schema::hasColumn('positions', 'customer_id'));
        $this->assertFalse(Schema::hasColumn('positions', 'enrollment_id'));
    }

    #[Test]
    public function the_customer_on_a_position_is_never_reassigned(): void
    {
        $position = Position::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $position->forceFill(['customer_id' => Customer::factory()->create()->getKey()])->save();
    }

    #[Test]
    public function a_position_records_who_entered_it(): void
    {
        $actor = $this->admin();
        $position = $this->positions->create(Customer::factory()->create(), 'Manager', actor: $actor);

        $this->assertSame(ActorSource::InternalUser, $position->source);
        $this->assertSame($actor->getKey(), $position->created_by);
    }

    #[Test]
    public function a_grant_for_another_business_cannot_write_a_position(): void
    {
        $customer = Customer::factory()->create();
        $foreignGrant = AccessGrant::factory()->create(['ability' => GrantAbility::CompleteForm]);

        $this->expectException(CustomerIsolationException::class);

        $this->positions->create($customer, 'Not mine', grant: $foreignGrant);
    }
}
