<?php

declare(strict_types=1);

namespace App\Domain\Sessions;

use App\Enums\AuditAction;
use App\Models\Batch;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Scheduling a curriculum session against a batch.
 *
 * INVARIANT I17: the session template's program must be the batch's program.
 * The check spans two rows, so no foreign key can express it. Without it a
 * cohort can be scheduled to sit another program's session, and the mistake
 * only surfaces when the wrong worksheets appear in the workspace.
 *
 * A session is CANCELLED, NEVER DELETED. A moved date changes every downstream
 * reminder, so who moved it is recorded.
 */
class SessionSchedulingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function schedule(
        Batch $batch,
        SessionTemplate $template,
        DateTimeInterface|string $plannedDate,
        ?User $actor = null,
        ?string $venue = null,
        ?User $conductedBy = null,
        ?string $startsAt = null,
    ): SessionInstance {
        $this->assertSameProgram($batch, $template);

        return DB::transaction(function () use ($batch, $template, $plannedDate, $actor, $venue, $conductedBy, $startsAt): SessionInstance {
            $instance = new SessionInstance;
            $instance->forceFill([
                'batch_id' => $batch->getKey(),
                'session_template_id' => $template->getKey(),
                'planned_date' => $plannedDate,
                'starts_at' => $startsAt,
                'venue' => $venue,
                'status' => SessionInstance::STATUS_SCHEDULED,
                'conducted_by' => $conductedBy?->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::SessionScheduled,
                $instance,
                null,
                [
                    'batch_id' => $batch->getKey(),
                    'session_template_id' => $template->getKey(),
                    'planned_date' => $instance->planned_date?->toDateString(),
                ],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * Instantiate a batch's whole curriculum in one transaction.
     *
     * The caller supplies a planned date per sequence rather than a start date
     * and a cadence: the SOW gives no fixed interval between sessions, and
     * inventing one would put dates in the calendar nobody agreed.
     *
     * @param  array<int, DateTimeInterface|string>  $plannedDates  keyed by session sequence
     * @return array<int, SessionInstance>
     */
    public function scheduleCurriculum(Batch $batch, array $plannedDates, ?User $actor = null): array
    {
        $templates = SessionTemplate::query()
            ->where('program_id', $batch->program_id)
            ->whereNull('archived_at')
            ->orderBy('sequence')
            ->get();

        return DB::transaction(function () use ($templates, $batch, $plannedDates, $actor): array {
            $instances = [];

            foreach ($templates as $template) {
                $sequence = (int) $template->sequence;

                if (! array_key_exists($sequence, $plannedDates)) {
                    throw new InvalidArgumentException(
                        "No planned date supplied for session {$sequence}."
                    );
                }

                $instances[$sequence] = $this->schedule(
                    $batch,
                    $template,
                    $plannedDates[$sequence],
                    $actor,
                );
            }

            return $instances;
        });
    }

    /**
     * Move a planned date. Every downstream reminder reads it, so it is
     * audited as its own action rather than folded into a generic update.
     */
    public function reschedule(
        SessionInstance $instance,
        DateTimeInterface|string $plannedDate,
        User $actor,
        ?string $reason = null,
    ): SessionInstance {
        $this->assertNotCancelled($instance);

        return DB::transaction(function () use ($instance, $plannedDate, $actor, $reason): SessionInstance {
            $before = $instance->planned_date?->toDateString();

            $instance->forceFill(['planned_date' => $plannedDate])->save();

            $this->audit->log(
                AuditAction::SessionRescheduled,
                $instance,
                ['planned_date' => $before],
                ['planned_date' => $instance->fresh()->planned_date?->toDateString(), 'reason' => $reason],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * Mark the session as under way on the day it is actually held.
     *
     * This is what makes attendance markable: I16 requires a HELD session, and
     * actual_date is what "held" means.
     */
    public function begin(
        SessionInstance $instance,
        DateTimeInterface|string $actualDate,
        User $actor,
    ): SessionInstance {
        $this->assertNotCancelled($instance);

        return DB::transaction(function () use ($instance, $actualDate, $actor): SessionInstance {
            $before = $instance->status;

            $instance->forceFill([
                'status' => SessionInstance::STATUS_IN_PROGRESS,
                'actual_date' => $actualDate,
            ])->save();

            $this->audit->log(
                AuditAction::SessionScheduled,
                $instance,
                ['status' => $before],
                [
                    'status' => SessionInstance::STATUS_IN_PROGRESS,
                    'actual_date' => $instance->fresh()->actual_date?->toDateString(),
                ],
                $actor,
            );

            return $instance->fresh();
        });
    }

    public function complete(
        SessionInstance $instance,
        User $actor,
        DateTimeInterface|string|null $actualDate = null,
    ): SessionInstance {
        $this->assertNotCancelled($instance);

        return DB::transaction(function () use ($instance, $actor, $actualDate): SessionInstance {
            $before = ['status' => $instance->status, 'actual_date' => $instance->actual_date?->toDateString()];

            $instance->forceFill([
                'status' => SessionInstance::STATUS_COMPLETED,
                // A completed session must have happened on a date. Falling
                // back to today is wrong if it is completed retrospectively,
                // so the caller may pass the real one.
                'actual_date' => $actualDate ?? $instance->actual_date ?? now()->toDateString(),
            ])->save();

            $this->audit->log(
                AuditAction::SessionCompleted,
                $instance,
                $before,
                [
                    'status' => SessionInstance::STATUS_COMPLETED,
                    'actual_date' => $instance->fresh()->actual_date?->toDateString(),
                ],
                $actor,
            );

            return $instance->fresh();
        });
    }

    public function cancel(SessionInstance $instance, User $actor, string $reason): SessionInstance
    {
        return DB::transaction(function () use ($instance, $actor, $reason): SessionInstance {
            $before = $instance->status;

            $instance->forceFill(['status' => SessionInstance::STATUS_CANCELLED])->save();

            $this->audit->log(
                AuditAction::SessionCancelled,
                $instance,
                ['status' => $before],
                ['status' => SessionInstance::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $instance->fresh();
        });
    }

    /**
     * INVARIANT I17.
     */
    public function assertSameProgram(Batch $batch, SessionTemplate $template): void
    {
        if ((int) $batch->program_id !== (int) $template->program_id) {
            throw new InvalidArgumentException(sprintf(
                'Session template %d belongs to program %d, but batch %d runs program %d.',
                $template->getKey(),
                $template->program_id,
                $batch->getKey(),
                $batch->program_id,
            ));
        }
    }

    private function assertNotCancelled(SessionInstance $instance): void
    {
        if ($instance->isCancelled()) {
            throw new RuntimeException(
                "Session instance {$instance->getKey()} is cancelled and cannot be changed."
            );
        }
    }
}
