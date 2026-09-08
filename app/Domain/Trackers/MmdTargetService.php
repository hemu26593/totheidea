<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Enums\AuditAction;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Setting targets.
 *
 * TARGETS ARE SEPARATE FROM ACTUALS. A target is an intention set internally
 * for a programme run; an MMD entry is what the business actually did. They
 * live in different tables with different owners, and nothing joins them at
 * rest - the comparison is computed on demand by TargetVsActualCalculator.
 *
 * Setting a target is an internal administrative act: Staff does not hold
 * mmd.set_targets, and there is no actor triple here because an external grant
 * can never reach this table by construction. Authorization is enforced by
 * MmdTargetPolicy at the call site.
 *
 * [L5] If targets turn out to be set per weekday - T / Th / S - this table
 * gains ONE nullable weekday column. Additive, so it waits.
 */
class MmdTargetService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Set or revise a target.
     *
     * The period identity is the key, so revising a target changes the row and
     * writes an audit entry rather than inserting a second one - two targets
     * for one period would make every comparison against it ambiguous.
     */
    public function set(
        Enrollment $enrollment,
        string $metric,
        string $periodType,
        DateTimeInterface|string $periodStart,
        DateTimeInterface|string $periodEnd,
        float $targetValue,
        User $actor,
    ): MmdTarget {
        $this->assertMetricIsKnown($metric);
        $this->assertPeriodTypeIsKnown($periodType);

        $start = CarbonImmutable::parse($periodStart)->startOfDay();
        $end = CarbonImmutable::parse($periodEnd)->startOfDay();

        if ($end < $start) {
            throw new InvalidArgumentException('A target period cannot end before it starts.');
        }

        if ($targetValue < 0) {
            throw new InvalidArgumentException('A target cannot be negative.');
        }

        return DB::transaction(function () use ($enrollment, $metric, $periodType, $start, $end, $targetValue, $actor): MmdTarget {
            // Located rather than firstOrNew()d: nothing on this model is
            // fillable, so the period identity is matched by query and the row
            // is built explicitly.
            $target = MmdTarget::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('metric', $metric)
                ->where('period_type', $periodType)
                ->whereDate('period_start', $start->toDateString())
                ->first() ?? new MmdTarget;

            $before = $target->exists ? ['target_value' => $target->target_value] : null;

            $target->forceFill([
                'enrollment_id' => $enrollment->getKey(),
                'metric' => $metric,
                'period_type' => $periodType,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'target_value' => $targetValue,
                'set_by' => $actor->getKey(),
                'set_at' => now(),
            ])->save();

            $fresh = $target->fresh();

            $this->audit->log(
                AuditAction::MmdTargetSet,
                $fresh,
                $before,
                [
                    'metric' => $metric,
                    'period_type' => $periodType,
                    'period_start' => $start->toDateString(),
                    'target_value' => $targetValue,
                ],
                $actor,
            );

            return $fresh;
        });
    }

    public function find(
        Enrollment $enrollment,
        string $metric,
        string $periodType,
        DateTimeInterface|string $periodStart,
    ): ?MmdTarget {
        return MmdTarget::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('metric', $metric)
            ->where('period_type', $periodType)
            ->whereDate('period_start', CarbonImmutable::parse($periodStart)->toDateString())
            ->first();
    }

    private function assertMetricIsKnown(string $metric): void
    {
        // Mirrors the mmd_entries measure set exactly, read from the same
        // constant, so a target can never be set for something that is never
        // recorded.
        if (! in_array($metric, MmdEntry::METRICS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown MMD metric [%s]. Targets mirror the recorded measure set: %s.',
                $metric,
                implode(', ', MmdEntry::METRICS),
            ));
        }
    }

    private function assertPeriodTypeIsKnown(string $periodType): void
    {
        if (! in_array($periodType, MmdTarget::PERIOD_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown period type [%s]. Expected one of: %s.',
                $periodType,
                implode(', ', MmdTarget::PERIOD_TYPES),
            ));
        }
    }
}
