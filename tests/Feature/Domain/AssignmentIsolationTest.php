<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Assignments\AssignmentSubmissionService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\SessionInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * INVARIANT I4 and the two mandatory parents.
 *
 * The multi-hop check is the one a foreign key cannot make:
 *
 *     assignment_instance -> session_instance -> batch
 *     enrollment                              -> batch
 *
 * Without it, filing work against another cohort's assignment looks entirely
 * normal in every listing, which is what makes it worth its own test file.
 */
class AssignmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    private AssignmentSubmissionService $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->submissions = app(AssignmentSubmissionService::class);
    }

    #[Test]
    public function both_parents_are_mandatory_in_the_schema(): void
    {
        // Neither parent alone identifies a submission, so neither is
        // nullable.
        $columns = collect(Schema::getColumns('assignment_submissions'))
            ->keyBy('name');

        $this->assertFalse($columns['assignment_instance_id']['nullable']);
        $this->assertFalse($columns['enrollment_id']['nullable']);
        $this->assertFalse($columns['customer_id']['nullable']);
    }

    #[Test]
    public function work_cannot_be_filed_against_another_batchs_released_assignment(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);

        // A perfectly valid enrolment - in a different cohort.
        $otherBatch = Batch::factory()->create();
        $outsider = Enrollment::factory()->create(['batch_id' => $otherBatch->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        $this->submissions->submit($instance, $outsider, 'Not my assignment.');
    }

    #[Test]
    public function the_same_batch_check_is_reported_as_a_batch_mismatch(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $outsider = Enrollment::factory()->create(['batch_id' => Batch::factory()->create()->getKey()]);

        try {
            $this->submissions->assertSameBatch($instance, $outsider);
            $this->fail('Expected the cross-batch pairing to be refused.');
        } catch (CustomerIsolationException $e) {
            $this->assertStringContainsString('batch', $e->getMessage());
        }
    }

    #[Test]
    public function work_from_the_right_batch_is_accepted(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $submission = $this->submissions->submit($instance, $enrollment, 'Here it is.');

        $this->assertSame($instance->getKey(), $submission->assignment_instance_id);
        $this->assertSame($enrollment->getKey(), $submission->enrollment_id);
    }

    // --- Invariant I15 -----------------------------------------------------

    #[Test]
    public function the_redundant_customer_id_comes_from_the_enrolment(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $submission = $this->submissions->submit($instance, $enrollment, 'Body');

        $this->assertSame((int) $enrollment->customer_id, (int) $submission->customer_id);
    }

    #[Test]
    public function a_submission_cannot_be_created_without_a_customer(): void
    {
        $submission = new AssignmentSubmission;
        $submission->forceFill([
            'assignment_instance_id' => AssignmentInstance::factory()->released()->create()->getKey(),
            'enrollment_id' => Enrollment::factory()->create()->getKey(),
            'status' => AssignmentSubmission::STATUS_IN_PROGRESS,
            'source' => ActorSource::System,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be created without a customer_id');

        $submission->save();
    }

    #[Test]
    public function the_customer_on_a_submission_is_never_reassigned(): void
    {
        $submission = AssignmentSubmission::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $submission->forceFill(['customer_id' => Customer::factory()->create()->getKey()])->save();
    }

    #[Test]
    public function the_scope_helper_constrains_to_one_customer(): void
    {
        $mine = AssignmentSubmission::factory()->create();
        AssignmentSubmission::factory()->create();

        $found = AssignmentSubmission::query()->forCustomer((int) $mine->customer_id)->get();

        $this->assertCount(1, $found);
        $this->assertSame($mine->getKey(), $found->first()->getKey());
    }

    // --- External submission ----------------------------------------------

    #[Test]
    public function an_externally_submitted_assignment_carries_the_grant_not_a_user(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);

        $grant = AccessGrant::factory()->create([
            'customer_id' => $enrollment->customer_id,
            'enrollment_id' => $enrollment->getKey(),
            'ability' => GrantAbility::SubmitAssignment,
            'subject_type' => $instance->getMorphClass(),
            'subject_id' => $instance->getKey(),
        ]);

        $submission = $this->submissions->submit($instance, $enrollment, 'Sent from the link.', grant: $grant);

        $this->assertSame(ActorSource::ExternalGrant, $submission->source);
        $this->assertSame($grant->getKey(), $submission->access_grant_id);
        $this->assertNull($submission->created_by);
    }

    #[Test]
    public function a_grant_issued_for_another_enrolment_cannot_submit_here(): void
    {
        $session = SessionInstance::factory()->held()->create();
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
        ]);
        $enrollment = Enrollment::factory()->create(['batch_id' => $session->batch_id]);
        $foreignGrant = AccessGrant::factory()->create(['ability' => GrantAbility::SubmitAssignment]);

        $this->expectException(CustomerIsolationException::class);

        $this->submissions->submit($instance, $enrollment, 'Body', grant: $foreignGrant);
    }

    #[Test]
    public function a_submission_is_never_removed(): void
    {
        $submission = AssignmentSubmission::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never removed');

        $submission->delete();
    }
}
