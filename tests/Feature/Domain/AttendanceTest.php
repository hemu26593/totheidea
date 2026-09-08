<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Sessions\AttendanceService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Attendance marking, amendment and isolation.
 *
 * Everything here is settled. How `late` and `excused` weigh in the 90% rule
 * is NOT, and none of it is exercised here - see AttendanceWeightingTest.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->attendance = app(AttendanceService::class);
    }

    /**
     * A held session with one enrolment in its batch.
     *
     * @return array{0: SessionInstance, 1: Enrollment}
     */
    private function heldSessionWithEnrollment(): array
    {
        $instance = SessionInstance::factory()->held()->create();
        $enrollment = Enrollment::factory()->create(['batch_id' => $instance->batch_id]);

        return [$instance, $enrollment];
    }

    // --- Storage and the four statuses ------------------------------------

    #[Test]
    public function all_four_statuses_are_storable(): void
    {
        foreach (SessionAttendance::STATUSES as $status) {
            [$instance, $enrollment] = $this->heldSessionWithEnrollment();

            $mark = $this->attendance->mark($instance, $enrollment, $status, $this->admin());

            $this->assertSame($status, $mark->status);
        }

        $this->assertSame(
            ['present', 'absent', 'late', 'excused'],
            SessionAttendance::STATUSES,
        );
    }

    #[Test]
    public function an_unknown_status_is_refused(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown attendance status');

        $this->attendance->mark($instance, $enrollment, 'maybe', $this->admin());
    }

    // --- Double marking ----------------------------------------------------

    #[Test]
    public function a_participant_cannot_be_marked_twice_for_one_session(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();
        $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already marked');

        $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_ABSENT, $this->admin());
    }

    #[Test]
    public function double_marking_is_blocked_by_the_database_not_only_by_the_service(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();
        $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $this->admin());

        // Going around the service entirely: two marks would silently corrupt
        // a contractual completion figure, so the constraint is in the schema.
        $this->expectException(QueryException::class);

        SessionAttendance::factory()->create([
            'session_instance_id' => $instance->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $enrollment->customer_id,
        ]);
    }

    #[Test]
    public function the_double_marking_constraint_is_a_database_constraint(): void
    {
        $unique = collect(Schema::getIndexes('session_attendances'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertTrue($unique->contains(['session_instance_id', 'enrollment_id']));
    }

    // --- Invariant I16 -----------------------------------------------------

    #[Test]
    public function attendance_cannot_be_marked_against_a_session_that_has_not_happened(): void
    {
        $instance = SessionInstance::factory()->create(); // scheduled, no actual_date
        $enrollment = Enrollment::factory()->create(['batch_id' => $instance->batch_id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may only be marked against a session that was held');

        $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $this->admin());
    }

    // --- Isolation ---------------------------------------------------------

    #[Test]
    public function an_enrolment_from_another_batch_cannot_appear_in_this_register(): void
    {
        $instance = SessionInstance::factory()->held()->create();
        $other = Enrollment::factory()->create(['batch_id' => Batch::factory()->create()->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        $this->attendance->mark($instance, $other, SessionAttendance::STATUS_PRESENT, $this->admin());
    }

    #[Test]
    public function the_redundant_customer_id_is_taken_from_the_enrolment(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();

        $mark = $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $this->admin());

        // I15: never from a request, always from the spine.
        $this->assertSame((int) $enrollment->customer_id, (int) $mark->customer_id);
    }

    #[Test]
    public function the_customer_on_a_mark_is_never_reassigned(): void
    {
        $mark = SessionAttendance::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $mark->forceFill(['customer_id' => Customer::factory()->create()->getKey()])->save();
    }

    // --- Actor triple and [CLIENT DECISION - S4] ---------------------------

    #[Test]
    public function an_externally_marked_attendance_carries_the_grant_and_no_internal_user(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();

        $grant = AccessGrant::factory()->create([
            'customer_id' => $enrollment->customer_id,
            'enrollment_id' => $enrollment->getKey(),
            'ability' => GrantAbility::MarkAttendance,
            'subject_type' => $instance->getMorphClass(),
            'subject_id' => $instance->getKey(),
        ]);

        $mark = $this->attendance->mark(
            $instance,
            $enrollment,
            SessionAttendance::STATUS_PRESENT,
            grant: $grant,
            markingMethod: 'participant_code',
        );

        $this->assertSame(ActorSource::ExternalGrant, $mark->source);
        $this->assertSame($grant->getKey(), $mark->access_grant_id);
        $this->assertNull($mark->created_by);
        // marked_by is null for an external mark: there is no internal user
        // behind a participant code.
        $this->assertNull($mark->marked_by);
    }

    #[Test]
    public function a_grant_for_another_enrolment_cannot_mark_this_one(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();
        $grant = AccessGrant::factory()->create(['ability' => GrantAbility::MarkAttendance]);

        $this->expectException(CustomerIsolationException::class);

        $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, grant: $grant);
    }

    #[Test]
    public function the_marking_method_is_optional_and_nothing_is_assumed_about_it(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();

        // [CLIENT DECISION - S4]. SOW section 9.2 q4 is unanswered, so no
        // default is written: a null marking_method is a legitimate row.
        $mark = $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $this->admin());

        $this->assertNull($mark->marking_method);
        $this->assertSame(['consultant', 'participant_code', 'venue_qr'], SessionAttendance::MARKING_METHODS);
    }

    #[Test]
    public function an_unknown_marking_method_is_refused(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown marking method');

        $this->attendance->mark(
            $instance,
            $enrollment,
            SessionAttendance::STATUS_PRESENT,
            $this->admin(),
            markingMethod: 'honour_system',
        );
    }

    // --- History -----------------------------------------------------------

    #[Test]
    public function marking_is_audited(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();
        $actor = $this->admin();

        $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $actor);

        $entry = AuditLog::query()->where('action', AuditAction::AttendanceMarked)->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame($actor->getKey(), $entry->actor_id);
        $this->assertSame('present', $entry->new_values['status']);
    }

    #[Test]
    public function an_amendment_keeps_the_previous_status_in_the_audit_trail(): void
    {
        [$instance, $enrollment] = $this->heldSessionWithEnrollment();
        $mark = $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_ABSENT, $this->admin());

        $amended = $this->attendance->amend($mark, SessionAttendance::STATUS_PRESENT, $this->admin(), 'Arrived, register wrong');

        $this->assertSame('present', $amended->status);
        $this->assertTrue($amended->wasAmended());

        $entry = AuditLog::query()->where('action', AuditAction::AttendanceAmended)->latest('id')->first();
        $this->assertNotNull($entry);
        // An amended mark changes a contractual completion figure, so what it
        // was must survive.
        $this->assertSame('absent', $entry->old_values['status']);
        $this->assertSame('present', $entry->new_values['status']);
    }

    #[Test]
    public function an_attendance_mark_can_never_be_deleted(): void
    {
        $mark = SessionAttendance::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('amended with audit, never deleted');

        $mark->delete();
    }

    #[Test]
    public function not_even_a_super_admin_can_delete_an_attendance_mark(): void
    {
        // Gate::before grants a Super Admin every ability outside
        // guarded_abilities, so the guarantee lives on the model rather than
        // in SessionAttendancePolicy.
        $this->actingAs($this->superAdmin());

        $this->expectException(RuntimeException::class);

        SessionAttendance::factory()->create()->delete();
    }

    #[Test]
    public function the_register_lists_every_mark_for_a_session(): void
    {
        $instance = SessionInstance::factory()->held()->create();

        foreach (range(1, 3) as $ignored) {
            $enrollment = Enrollment::factory()->create(['batch_id' => $instance->batch_id]);
            $this->attendance->mark($instance, $enrollment, SessionAttendance::STATUS_PRESENT, $this->admin());
        }

        $this->assertCount(3, $this->attendance->register($instance));
    }

    #[Test]
    public function no_percentage_is_stored_on_an_attendance_row(): void
    {
        // The 90% figure is computed and never stored. A column here would go
        // stale the moment a mark was amended.
        foreach (['percentage', 'attendance_percentage', 'completion_percentage', 'is_reachable'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('session_attendances', $column),
                "session_attendances must not store [{$column}] - the figure is derived."
            );
        }
    }
}
