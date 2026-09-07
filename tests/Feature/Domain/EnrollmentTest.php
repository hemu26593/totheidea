<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Programme\EnrollmentService;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Enrolment is the ownership spine. If it is wrong, every downstream
 * attendance percentage and completion score is wrong with it.
 */
class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentService $enrollments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->enrollments = app(EnrollmentService::class);
    }

    #[Test]
    public function a_customer_can_be_enrolled_in_a_batch(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $batch = Batch::factory()->create();

        $enrollment = $this->enrollments->enrol($customer, $batch, $actor);

        $this->assertSame($customer->getKey(), $enrollment->customer_id);
        $this->assertSame($batch->getKey(), $enrollment->batch_id);
        $this->assertSame('enrolled', $enrollment->status);
        $this->assertNotNull($enrollment->enrolled_at);
    }

    #[Test]
    public function the_same_customer_cannot_enrol_twice_in_one_batch(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $batch = Batch::factory()->create();

        $this->enrollments->enrol($customer, $batch, $actor);

        $this->expectException(RuntimeException::class);

        $this->enrollments->enrol($customer, $batch, $actor);
    }

    #[Test]
    public function the_duplicate_rule_is_enforced_by_the_database_too(): void
    {
        // The service check is the readable error; the unique index is the
        // guarantee, and it holds even if a caller bypasses the service.
        $customer = Customer::factory()->create();
        $batch = Batch::factory()->create();

        Enrollment::factory()->create(['customer_id' => $customer->getKey(), 'batch_id' => $batch->getKey()]);

        $this->expectException(QueryException::class);

        Enrollment::factory()->create(['customer_id' => $customer->getKey(), 'batch_id' => $batch->getKey()]);
    }

    #[Test]
    public function a_withdrawn_enrolment_still_blocks_a_duplicate(): void
    {
        // The unique key deliberately excludes status: re-participation is a
        // LATER batch, not a second row in the same one.
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $batch = Batch::factory()->create();

        $enrollment = $this->enrollments->enrol($customer, $batch, $actor);
        $this->enrollments->withdraw($enrollment, 'Changed plans', $actor);

        $this->expectException(RuntimeException::class);

        $this->enrollments->enrol($customer, $batch, $actor);
    }

    #[Test]
    public function repeat_participation_in_a_later_batch_is_permitted(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $first = Batch::factory()->create();
        $second = Batch::factory()->create();

        $this->enrollments->enrol($customer, $first, $actor);
        $this->enrollments->enrol($customer, $second, $actor);

        $this->assertSame(2, $customer->enrollments()->count());
    }

    #[Test]
    public function two_customers_may_share_a_batch(): void
    {
        $actor = $this->admin();
        $batch = Batch::factory()->create();

        $this->enrollments->enrol(Customer::factory()->create(), $batch, $actor);
        $this->enrollments->enrol(Customer::factory()->create(), $batch, $actor);

        $this->assertSame(2, $batch->enrollments()->count());
    }

    #[Test]
    public function batch_capacity_is_enforced(): void
    {
        $actor = $this->admin();
        $batch = Batch::factory()->withCapacity(1)->create();

        $this->enrollments->enrol(Customer::factory()->create(), $batch, $actor);

        $this->expectException(RuntimeException::class);

        $this->enrollments->enrol(Customer::factory()->create(), $batch, $actor);
    }

    #[Test]
    public function pairing_an_enrolment_with_another_customer_is_refused(): void
    {
        $actor = $this->admin();
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $enrollment = $this->enrollments->enrol($a, Batch::factory()->create(), $actor);

        $this->expectException(CustomerIsolationException::class);

        $this->enrollments->assertBelongsTo($enrollment, $b);
    }

    #[Test]
    public function an_enrolment_resolves_to_exactly_one_customer_and_one_batch(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $batch = Batch::factory()->create();

        $enrollment = $this->enrollments->enrol($customer, $batch, $actor);

        $this->assertTrue($enrollment->customer->is($customer));
        $this->assertTrue($enrollment->batch->is($batch));
        $this->assertTrue($enrollment->batch->program->is($batch->program));
    }

    #[Test]
    public function withdrawal_and_completion_are_audited_and_never_delete(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $enrollment = $this->enrollments->enrol($customer, Batch::factory()->create(), $actor);

        $this->enrollments->withdraw($enrollment, 'Business closed', $actor);

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->getKey(), 'status' => 'withdrawn']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::EnrollmentWithdrawn->value,
            'auditable_id' => $enrollment->getKey(),
        ]);

        $other = $this->enrollments->enrol(Customer::factory()->create(), Batch::factory()->create(), $actor);
        $this->enrollments->complete($other, $actor);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::EnrollmentCompleted->value,
            'auditable_id' => $other->getKey(),
        ]);
    }

    #[Test]
    public function a_customer_with_enrolments_cannot_be_deleted_at_the_database_level(): void
    {
        // enrollments RESTRICTs, so an attempted delete raises rather than
        // sweeping a programme run's history.
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $this->enrollments->enrol($customer, Batch::factory()->create(), $actor);

        $this->expectException(QueryException::class);

        DB::table('customers')->where('id', $customer->getKey())->delete();
    }
}
