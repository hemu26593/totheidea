<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\SessionAttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One attendance mark: one enrolment, one session instance.
 *
 * The 90% completion figure is derived from these rows and never stored, so a
 * mark is the only fact and every report recomputes from it.
 *
 * AMENDABLE WITH AUDIT, NEVER DELETED. Deleting a mark would silently change a
 * contractual completion figure and leave nothing behind saying so, which is
 * why the guard is on the model rather than only in the policy.
 *
 * Mass-assignment note: nothing is fillable. AttendanceService sets the
 * status, the actor triple and the redundant customer_id, and audits.
 */
#[Fillable([])]
class SessionAttendance extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<SessionAttendanceFactory> */
    use HasFactory;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUS_EXCUSED = 'excused';

    /**
     * The four confirmed statuses.
     *
     * `present` counts toward the 90% and `absent` does not - those are
     * settled. How `late` and `excused` weigh is
     * [CLIENT DECISION - ATTENDANCE WEIGHTING] and is answered by an
     * AttendanceWeighting implementation, not by this list.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
        self::STATUS_EXCUSED,
    ];

    /**
     * [CLIENT DECISION - S4] How attendance is marked. Nullable with no
     * default: nothing is assumed about a method nobody has confirmed.
     *
     * @var array<int, string>
     */
    public const MARKING_METHODS = [
        'consultant',
        'participant_code',
        'venue_qr',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'marked_at' => 'datetime',
            'amended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'An attendance mark is amended with audit, never deleted. It is an input to a '
                .'contractual completion figure.'
            );
        });
    }

    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    public function wasAmended(): bool
    {
        return $this->amended_at !== null;
    }
}
