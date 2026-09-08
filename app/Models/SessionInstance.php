<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SessionInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A session as delivered to one batch.
 *
 * CANCELLED, NEVER DELETED. The deleting guard below is the absolute form of
 * that rule: a policy returning false is bypassed for a Super Admin by
 * Gate::before on any ability that is not in authorization.guarded_abilities,
 * so the rule cannot live only in SessionInstancePolicy.
 *
 * Mass-assignment note: nothing is fillable. Scheduling, completion and
 * cancellation go through SessionSchedulingService, which audits them and
 * enforces I17.
 */
#[Fillable([])]
class SessionInstance extends Model
{
    /** @use HasFactory<SessionInstanceFactory> */
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The whole vocabulary, in one place. The column is VARCHAR rather than a
     * native ENUM so the schema stays portable between SQLite and MySQL.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'actual_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'A session instance is cancelled, never deleted. Attendance and released '
                .'assignments refer to it.'
            );
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function sessionTemplate(): BelongsTo
    {
        return $this->belongsTo(SessionTemplate::class);
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendance::class);
    }

    public function assignmentInstances(): HasMany
    {
        return $this->hasMany(AssignmentInstance::class);
    }

    /**
     * A session that has actually taken place. Attendance may only be marked
     * against one of these (invariant I16).
     */
    public function isHeld(): bool
    {
        return in_array($this->status, [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED], true)
            && $this->actual_date !== null;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
