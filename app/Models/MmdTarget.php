<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MmdTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A target for one metric over one period.
 *
 * SEPARATE FROM ACTUALS BY DESIGN. Targets are an intention set internally for
 * a programme run; MMD entries are what the business actually did. Storing a
 * target alongside an actual on one row would make "what did we aim for?"
 * unanswerable the moment either changed.
 *
 * TARGET-VERSUS-ACTUAL IS NEVER STORED. It is computed by
 * TargetVsActualCalculator from these rows and the entries, so it cannot go
 * stale when either side is amended.
 *
 * No actor triple: setting a target is an internal administrative act, and
 * set_by records who did it. Staff does not hold mmd.set_targets.
 */
#[Fillable([])]
class MmdTarget extends Model
{
    /** @use HasFactory<MmdTargetFactory> */
    use HasFactory;

    public const PERIOD_DAILY = 'daily';

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_MONTHLY = 'monthly';

    /** @var array<int, string> */
    public const PERIOD_TYPES = [
        self::PERIOD_DAILY,
        self::PERIOD_WEEKLY,
        self::PERIOD_MONTHLY,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'target_value' => 'decimal:2',
            'set_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
