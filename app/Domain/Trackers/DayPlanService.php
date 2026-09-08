<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Enums\ActorSource;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\DayPlanItem;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The daily execution planner.
 *
 * DISTINCT FROM THE TIME GRID. That is quarterly strategic allocation and
 * lives in TimeGridService; nothing here has a quarter and nothing there has a
 * date. The two never share a table, a service or a vocabulary.
 *
 * DISTINCT FROM THE ACTION PLAN. An action item is an outstanding commitment
 * with a provenance; a day plan item is scheduled work on one date.
 *
 * CARRY-FORWARD COPIES FORWARD WITH A LINK. The original stays on its own date
 * marked carried_forward; the new row points back through carried_from_id.
 * Mutating plan_date in place would be simpler and wrong - yesterday would
 * retroactively show no unfinished work, and the slip chain, which is the
 * actual coaching signal, would be gone.
 */
class DayPlanService
{
    public function __construct(private readonly TrackerOwnership $ownership) {}

    public function create(
        Enrollment $enrollment,
        DateTimeInterface|string $planDate,
        string $task,
        ?User $actor = null,
        ?AccessGrant $grant = null,
        ?string $plannedStart = null,
        ?string $plannedEnd = null,
        ?string $g = null,
        ?string $c = null,
        ?string $m = null,
        int $position = 0,
    ): DayPlanItem {
        $this->assertSlotIsCoherent($plannedStart, $plannedEnd);
        $this->ownership->assertGrantMatchesEnrollment($grant, $enrollment);

        return DB::transaction(fn (): DayPlanItem => $this->insert(
            $enrollment,
            $planDate,
            $task,
            $actor,
            $grant,
            $plannedStart,
            $plannedEnd,
            $g,
            $c,
            $m,
            $position,
            null,
        ));
    }

    /**
     * Amend a planned item.
     *
     * A carried-forward or completed item is not edited: its state is the
     * record of what happened on its own day.
     */
    public function update(
        DayPlanItem $item,
        array $attributes,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): DayPlanItem {
        $this->assertIsStillPlanned($item);
        $this->ownership->assertGrantMatchesEnrollment($grant, $item->enrollment);

        $allowed = array_intersect_key($attributes, array_flip([
            'task', 'planned_start', 'planned_end', 'actual_minutes', 'position',
            // [CLIENT DECISION - S1] Carried exactly as supplied.
            'g', 'c', 'm',
        ]));

        $this->assertSlotIsCoherent(
            $allowed['planned_start'] ?? $item->planned_start,
            $allowed['planned_end'] ?? $item->planned_end,
        );

        return DB::transaction(function () use ($item, $allowed): DayPlanItem {
            $item->forceFill($allowed)->save();

            return $item->fresh();
        });
    }

    public function complete(
        DayPlanItem $item,
        ?int $actualMinutes = null,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): DayPlanItem {
        $this->assertIsStillPlanned($item);
        $this->ownership->assertGrantMatchesEnrollment($grant, $item->enrollment);

        return DB::transaction(function () use ($item, $actualMinutes): DayPlanItem {
            $item->forceFill([
                'status' => DayPlanItem::STATUS_DONE,
                'actual_minutes' => $actualMinutes ?? $item->actual_minutes,
            ])->save();

            return $item->fresh();
        });
    }

    /**
     * Carry every unfinished item from before $asOf forward by one day.
     *
     * IDEMPOTENT, TWICE OVER. The sweep selects only `planned` items, and its
     * first act on each is to mark the original `carried_forward` - so a
     * second run cannot see it. The explicit already-carried check below is
     * the belt to that braces: it means even a partially-applied run, or a
     * status changed back by hand, cannot produce two copies of one task.
     *
     * @return Collection<int, DayPlanItem> the newly created items
     */
    public function carryForward(DateTimeInterface|string|null $asOf = null, ?Enrollment $only = null): Collection
    {
        $today = CarbonImmutable::parse($asOf ?? now())->startOfDay();

        $query = DayPlanItem::query()
            ->where('status', DayPlanItem::STATUS_PLANNED)
            ->whereDate('plan_date', '<', $today->toDateString())
            ->orderBy('id');

        if ($only !== null) {
            $query->where('enrollment_id', $only->getKey());
        }

        $created = new Collection;

        foreach ($query->get() as $original) {
            $carried = DB::transaction(function () use ($original): ?DayPlanItem {
                if ($this->hasAlreadyBeenCarried($original)) {
                    // Settle the original's status anyway: a copy exists, so
                    // leaving it `planned` would make the sweep pick it up
                    // forever.
                    $original->forceFill(['status' => DayPlanItem::STATUS_CARRIED_FORWARD])->save();

                    return null;
                }

                // The original stays on its own date. This is the whole point.
                $original->forceFill(['status' => DayPlanItem::STATUS_CARRIED_FORWARD])->save();

                return $this->insert(
                    $original->enrollment,
                    $original->plan_date->copy()->addDay(),
                    $original->task,
                    null,
                    null,
                    $original->planned_start,
                    $original->planned_end,
                    $original->g,
                    $original->c,
                    $original->m,
                    (int) $original->position,
                    (int) $original->getKey(),
                    // The system carried it, not a person and not a grant.
                    ActorSource::System,
                );
            });

            if ($carried !== null) {
                $created->push($carried);
            }
        }

        return $created;
    }

    /**
     * One participant's plan for one day.
     *
     * @return Collection<int, DayPlanItem>
     */
    public function forDay(Enrollment $enrollment, DateTimeInterface|string $planDate): Collection
    {
        return DayPlanItem::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereDate('plan_date', CarbonImmutable::parse($planDate)->toDateString())
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many times a task has slipped, walking carried_from_id backwards.
     *
     * The coaching signal the copy-forward design exists to preserve.
     */
    public function slipCount(DayPlanItem $item): int
    {
        $slips = 0;
        $cursor = $item;

        while ($cursor->carried_from_id !== null) {
            $cursor = $cursor->carriedFrom()->first();

            if ($cursor === null) {
                // SET NULL on a broken chain degrades the explanation without
                // orphaning anything, so stop counting rather than fail.
                break;
            }

            $slips++;
        }

        return $slips;
    }

    private function insert(
        Enrollment $enrollment,
        DateTimeInterface|string $planDate,
        string $task,
        ?User $actor,
        ?AccessGrant $grant,
        ?string $plannedStart,
        ?string $plannedEnd,
        ?string $g,
        ?string $c,
        ?string $m,
        int $position,
        ?int $carriedFromId,
        ?ActorSource $forceSource = null,
    ): DayPlanItem {
        $item = new DayPlanItem;
        $item->forceFill([
            'enrollment_id' => $enrollment->getKey(),
            // Taken from the enrolment, never from a request.
            'customer_id' => $enrollment->customer_id,
            'plan_date' => $planDate,
            'task' => $task,
            'planned_start' => $plannedStart,
            'planned_end' => $plannedEnd,
            'status' => DayPlanItem::STATUS_PLANNED,
            'carried_from_id' => $carriedFromId,
            // [CLIENT DECISION - S1] Three columns, carried exactly as
            // supplied. Never expanded, renamed or guessed at.
            'g' => $g,
            'c' => $c,
            'm' => $m,
            'position' => $position,
            'source' => $forceSource ?? $this->ownership->resolveSource($actor, $grant),
            'created_by' => $forceSource === ActorSource::System ? null : $actor?->getKey(),
            'access_grant_id' => $forceSource === ActorSource::System ? null : $grant?->getKey(),
        ])->save();

        return $item->fresh();
    }

    private function hasAlreadyBeenCarried(DayPlanItem $original): bool
    {
        return DayPlanItem::query()
            ->where('carried_from_id', $original->getKey())
            ->exists();
    }

    /**
     * INVARIANT I14, in the direction it can be checked at write time: a
     * carried item lands on a LATER date than the one it came from, for the
     * SAME enrolment.
     */
    public function assertCarryChainIsCoherent(DayPlanItem $item): void
    {
        if ($item->carried_from_id === null) {
            return;
        }

        $origin = $item->carriedFrom()->first();

        if ($origin === null) {
            return;
        }

        if ((int) $origin->enrollment_id !== (int) $item->enrollment_id) {
            throw CustomerIsolationException::mismatch(
                'day plan item '.$item->getKey(),
                'enrolment '.$origin->enrollment_id,
                'enrolment '.$item->enrollment_id,
            );
        }

        if ($origin->plan_date >= $item->plan_date) {
            throw new RuntimeException(sprintf(
                'Day plan item %d is carried from %s, which is not earlier than its own date %s.',
                $item->getKey(),
                $origin->plan_date->toDateString(),
                $item->plan_date->toDateString(),
            ));
        }
    }

    private function assertIsStillPlanned(DayPlanItem $item): void
    {
        if (! $item->isPlanned()) {
            throw new RuntimeException(sprintf(
                'Day plan item %d is [%s]. A completed or carried-forward item is the record of '
                .'what happened on its own day and is not edited.',
                $item->getKey(),
                $item->status,
            ));
        }
    }

    private function assertSlotIsCoherent(?string $start, ?string $end): void
    {
        if ($start === null || $end === null) {
            return;
        }

        if ($end < $start) {
            throw new InvalidArgumentException(
                "A time slot cannot end ({$end}) before it starts ({$start})."
            );
        }
    }
}
