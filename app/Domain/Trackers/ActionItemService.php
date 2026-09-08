<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Models\AccessGrant;
use App\Models\ActionItem;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The participant's action list.
 *
 * ONE LIST PER ENROLMENT - the list IS the enrolment, so there is no header
 * table and no "list" entity to get out of step with its owner.
 *
 * NOT THE DAY PLAN. A day plan item is scheduled work on one date, in a time
 * slot, that carries forward when unfinished; an action item is an outstanding
 * commitment with a due date and a reason for existing. Merging them would
 * lose the reason - which is what makes an auto-fed list self-explaining -
 * and would give carry-forward semantics to things that should simply stay
 * open.
 *
 * NEVER REMOVED. `dropped` is a status, so an abandoned commitment still says
 * it was once made.
 */
class ActionItemService
{
    public function __construct(private readonly TrackerOwnership $ownership) {}

    public function create(
        Enrollment $enrollment,
        string $title,
        ?string $description = null,
        DateTimeInterface|string|null $dueDate = null,
        string $priority = ActionItem::PRIORITY_NORMAL,
        ?User $actor = null,
        ?AccessGrant $grant = null,
        ?Model $source = null,
        int $position = 0,
    ): ActionItem {
        $this->assertPriorityIsKnown($priority);
        $this->ownership->assertGrantMatchesEnrollment($grant, $enrollment);

        return DB::transaction(function () use (
            $enrollment, $title, $description, $dueDate, $priority, $actor, $grant, $source, $position
        ): ActionItem {
            $item = new ActionItem;
            $item->forceFill([
                'enrollment_id' => $enrollment->getKey(),
                'title' => $title,
                'description' => $description,
                // Provenance, not ownership. Null for a manual item.
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'due_date' => $dueDate === null ? null : CarbonImmutable::parse($dueDate)->toDateString(),
                'status' => ActionItem::STATUS_OPEN,
                'priority' => $priority,
                'position' => $position,
                'source' => $this->ownership->resolveSource($actor, $grant),
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            return $item->fresh();
        });
    }

    public function update(
        ActionItem $item,
        array $attributes,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): ActionItem {
        $this->ownership->assertGrantMatchesEnrollment($grant, $item->enrollment);

        $allowed = array_intersect_key($attributes, array_flip([
            'title', 'description', 'due_date', 'priority', 'position',
        ]));

        if (array_key_exists('priority', $allowed)) {
            $this->assertPriorityIsKnown((string) $allowed['priority']);
        }

        return DB::transaction(function () use ($item, $allowed): ActionItem {
            $item->forceFill($allowed)->save();

            return $item->fresh();
        });
    }

    /**
     * Move an item along. `done` is the only status that carries a timestamp,
     * because it is the only one that records a moment rather than a state.
     */
    public function transitionTo(
        ActionItem $item,
        string $status,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): ActionItem {
        $this->assertStatusIsKnown($status);
        $this->ownership->assertGrantMatchesEnrollment($grant, $item->enrollment);

        return DB::transaction(function () use ($item, $status): ActionItem {
            $item->forceFill([
                'status' => $status,
                'completed_at' => $status === ActionItem::STATUS_DONE ? now() : null,
            ])->save();

            return $item->fresh();
        });
    }

    public function complete(ActionItem $item, ?User $actor = null, ?AccessGrant $grant = null): ActionItem
    {
        return $this->transitionTo($item, ActionItem::STATUS_DONE, $actor, $grant);
    }

    public function drop(ActionItem $item, ?User $actor = null): ActionItem
    {
        return $this->transitionTo($item, ActionItem::STATUS_DROPPED, $actor);
    }

    /**
     * The open list for a participant, soonest due first.
     *
     * @return Collection<int, ActionItem>
     */
    public function openFor(Enrollment $enrollment): Collection
    {
        return ActionItem::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('status', [ActionItem::STATUS_OPEN, ActionItem::STATUS_IN_PROGRESS])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Guard against a caller pairing an item with another participant's list.
     */
    public function assertBelongsToEnrollment(ActionItem $item, Enrollment $enrollment): void
    {
        if ((int) $item->enrollment_id !== (int) $enrollment->getKey()) {
            throw new RuntimeException(sprintf(
                'Action item %d belongs to enrolment %d, not %d.',
                $item->getKey(),
                $item->enrollment_id,
                $enrollment->getKey(),
            ));
        }
    }

    private function assertStatusIsKnown(string $status): void
    {
        if (! in_array($status, ActionItem::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown action item status [%s]. Expected one of: %s.',
                $status,
                implode(', ', ActionItem::STATUSES),
            ));
        }
    }

    private function assertPriorityIsKnown(string $priority): void
    {
        // An ordered label, not a scoring system. There is no numeric mapping
        // for these values anywhere in the codebase.
        if (! in_array($priority, ActionItem::PRIORITIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown priority [%s]. Expected one of: %s.',
                $priority,
                implode(', ', ActionItem::PRIORITIES),
            ));
        }
    }
}
