<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\DayPlanItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One task on one day - DAILY EXECUTION.
 *
 * Not the Time Grid, which is quarterly strategic allocation, and not an
 * action item, which is an outstanding commitment with a provenance. Three
 * related instruments, three tables, three services.
 *
 * NEVER DELETED. Each day keeps what it actually planned, which is what makes
 * the slip chain readable: mutating or removing yesterday's unfinished work
 * would erase the only evidence that it slipped.
 *
 * Mass-assignment note: nothing is fillable. DayPlanService sets the
 * enrolment, the redundant customer_id, the status and the actor triple.
 */
#[Fillable([])]
class DayPlanItem extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<DayPlanItemFactory> */
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_DONE = 'done';

    public const STATUS_CARRIED_FORWARD = 'carried_forward';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_DONE,
        self::STATUS_CARRIED_FORWARD,
    ];

    /**
     * [CLIENT DECISION - S1] The three columns the SOW places after Tasks.
     *
     * The count is settled at three; the labels are not. They are carried
     * exactly as supplied - g, c and m - and are never expanded, renamed or
     * guessed at anywhere in this codebase.
     *
     * @var array<int, string>
     */
    public const UNLABELLED_COLUMNS = ['g', 'c', 'm'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'plan_date' => 'date',
            'actual_minutes' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'A day plan item is never deleted. Unfinished work is carried forward, which '
                .'leaves the original on its own date as evidence that it slipped.'
            );
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    /**
     * The item this one was carried forward from, if any.
     */
    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_id');
    }

    /**
     * The next link in the slip chain.
     */
    public function carriedTo(): HasMany
    {
        return $this->hasMany(self::class, 'carried_from_id');
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function wasCarriedForward(): bool
    {
        return $this->status === self::STATUS_CARRIED_FORWARD;
    }
}
