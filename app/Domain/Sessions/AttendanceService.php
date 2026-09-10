<?php

declare(strict_types=1);

namespace App\Domain\Sessions;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Enrollment;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Marking and amending attendance.
 *
 * Four rules live here, none of which a column definition can express:
 *
 *   1. I16 - a mark may only be made against a session that was actually
 *      HELD. Marking a future session records something that has not
 *      happened.
 *   2. The enrolment and the session instance must belong to the SAME BATCH.
 *      Structurally the same guard as I4 on assignments: an enrolment from
 *      another cohort has no business in this register.
 *   3. I15 - the redundant customer_id is taken from the enrolment, never
 *      from a request.
 *   4. Double marking is refused. UNIQUE (session_instance_id,
 *      enrollment_id) makes it impossible; this service turns the database
 *      error into an intelligible one first.
 *
 * WHAT IS NOT HERE: the 90% calculation. It lives in AttendanceCalculator,
 * against the unbound AttendanceWeighting contract
 * ([CLIENT DECISION - ATTENDANCE WEIGHTING]). Marking does not depend on the
 * weighting and is fully built.
 */
class AttendanceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function mark(
        SessionInstance $instance,
        Enrollment $enrollment,
        string $status,
        ?User $actor = null,
        ?AccessGrant $grant = null,
        ?string $markingMethod = null,
    ): SessionAttendance {
        $this->assertStatusIsKnown($status);
        $this->assertMarkingMethodIsKnown($markingMethod);
        $this->assertSessionWasHeld($instance);
        $this->assertSameBatch($instance, $enrollment);
        $this->assertNotAlreadyMarked($instance, $enrollment);

        if ($grant !== null && (int) $grant->enrollment_id !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'grant '.$grant->getKey(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$grant->enrollment_id,
            );
        }

        $source = match (true) {
            $grant !== null => ActorSource::ExternalGrant,
            $actor !== null => ActorSource::InternalUser,
            default => ActorSource::System,
        };

        return DB::transaction(function () use ($instance, $enrollment, $status, $actor, $grant, $markingMethod, $source): SessionAttendance {
            $attendance = new SessionAttendance;
            $attendance->forceFill([
                'session_instance_id' => $instance->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                // Taken from the enrolment - never from the caller.
                'customer_id' => $enrollment->customer_id,
                'status' => $status,
                'marked_at' => now(),
                // Null for an external mark: there is no internal user behind
                // a participant code or a venue QR scan.
                'marked_by' => $grant === null ? $actor?->getKey() : null,
                'marking_method' => $markingMethod,
                'source' => $source,
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::AttendanceMarked,
                $attendance,
                null,
                [
                    'session_instance_id' => $instance->getKey(),
                    'enrollment_id' => $enrollment->getKey(),
                    'status' => $status,
                    'marking_method' => $markingMethod,
                ],
                $actor,
                $grant !== null ? ActorSource::ExternalGrant : null,
                $grant,
            );

            return $attendance->fresh();
        });
    }

    /**
     * Correct a mark.
     *
     * An amended mark changes a contractual completion figure, so the previous
     * status is written into the audit trail rather than simply overwritten,
     * and amended_at records that the row is no longer the original.
     */
    public function amend(
        SessionAttendance $attendance,
        string $status,
        User $actor,
        ?string $reason = null,
    ): SessionAttendance {
        $this->assertStatusIsKnown($status);

        return DB::transaction(function () use ($attendance, $status, $actor, $reason): SessionAttendance {
            $before = $attendance->status;

            $attendance->forceFill([
                'status' => $status,
                'amended_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::AttendanceAmended,
                $attendance,
                ['status' => $before],
                ['status' => $status, 'reason' => $reason],
                $actor,
            );

            return $attendance->fresh();
        });
    }

    /**
     * The attendance register for one session, in enrolment order.
     *
     * @return Collection<int, SessionAttendance>
     */
    public function register(SessionInstance $instance)
    {
        return SessionAttendance::query()
            ->where('session_instance_id', $instance->getKey())
            ->orderBy('enrollment_id')
            ->get();
    }

    public function hasBeenMarked(SessionInstance $instance, Enrollment $enrollment): bool
    {
        return SessionAttendance::query()
            ->where('session_instance_id', $instance->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->exists();
    }

    private function assertStatusIsKnown(string $status): void
    {
        if (! in_array($status, SessionAttendance::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown attendance status [%s]. Expected one of: %s.',
                $status,
                implode(', ', SessionAttendance::STATUSES),
            ));
        }
    }

    /**
     * [CLIENT DECISION - S4] Null stays legitimate: nothing is assumed about
     * how attendance is marked until the client says.
     */
    private function assertMarkingMethodIsKnown(?string $method): void
    {
        if ($method !== null && ! in_array($method, SessionAttendance::MARKING_METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown marking method [%s]. Expected one of: %s.',
                $method,
                implode(', ', SessionAttendance::MARKING_METHODS),
            ));
        }
    }

    /**
     * INVARIANT I16.
     */
    private function assertSessionWasHeld(SessionInstance $instance): void
    {
        if (! $instance->isHeld()) {
            throw new RuntimeException(sprintf(
                'Session instance %d is [%s] with actual_date [%s]; attendance may only be marked '
                .'against a session that was held.',
                $instance->getKey(),
                $instance->status,
                $instance->actual_date?->toDateString() ?? 'none',
            ));
        }
    }

    private function assertSameBatch(SessionInstance $instance, Enrollment $enrollment): void
    {
        if ((int) $instance->batch_id !== (int) $enrollment->batch_id) {
            throw CustomerIsolationException::mismatch(
                'enrolment '.$enrollment->getKey(),
                'batch '.$instance->batch_id,
                'batch '.$enrollment->batch_id,
            );
        }
    }

    private function assertNotAlreadyMarked(SessionInstance $instance, Enrollment $enrollment): void
    {
        if ($this->hasBeenMarked($instance, $enrollment)) {
            throw new RuntimeException(sprintf(
                'Enrolment %d is already marked for session instance %d. Amend the existing mark '
                .'rather than adding a second one - two marks would corrupt the completion figure.',
                $enrollment->getKey(),
                $instance->getKey(),
            ));
        }
    }
}
