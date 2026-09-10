<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\ActionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One outstanding commitment on a participant's action list.
 *
 * NOT A DAY PLAN ITEM. A day plan item is scheduled work on a date, in a time
 * slot, that carries forward when unfinished. An action item is a commitment
 * with a due date and a reason for existing. Merging them would lose the
 * reason, which is the only thing that makes the list self-explaining.
 *
 * NEVER REMOVED - `dropped` is a status, so a task that was abandoned still
 * says so.
 */
#[Fillable([])]
class ActionItem extends Model
{
    use HasActorTriple;

    /** @use HasFactory<ActionItemFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_DROPPED = 'dropped';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_DROPPED,
    ];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    /**
     * An ORDERED LABEL, not a scoring system. Nothing multiplies, sums or
     * ranks by these values, and no numeric mapping exists for them.
     *
     * @var array<int, string>
     */
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'An action item is never removed. An abandoned task is dropped, which keeps the '
                .'record that it was once committed to.'
            );
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    /**
     * Why this task exists - a released assignment, a scored skill area.
     * PROVENANCE, NOT OWNERSHIP: ownership is enrollment_id, and a dangling
     * source degrades an explanation without orphaning the row.
     */
    public function sourceRecord(): MorphTo
    {
        // __FUNCTION__ rather than 'source': the actor triple already owns
        // an attribute called source, and naming the relation after it would
        // make $item->source ambiguous.
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * Created by the auto-feed rather than by hand.
     */
    public function wasAutoFed(): bool
    {
        return $this->source_type !== null;
    }
}
