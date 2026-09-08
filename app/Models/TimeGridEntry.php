<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\TimeGridEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quarter's planned-versus-actual allocation for one activity.
 *
 * STRATEGIC ALLOCATION - Q1, Q2, Q3, Q4. This is not a task list and never
 * becomes one: there is no date, no status, no time slot and no carry-forward
 * here, because those belong to the Day Plan, which is a different instrument
 * answering a different question.
 *
 * Amendable with audit: actuals arrive after planning, which is the entire
 * point of holding planned_hours and actual_hours side by side.
 */
#[Fillable([])]
class TimeGridEntry extends Model
{
    use HasActorTriple;

    /** @use HasFactory<TimeGridEntryFactory> */
    use HasFactory;

    /**
     * The four quarters. Fixed vocabulary - there is no Q5 and no
     * configurable period length.
     *
     * @var array<int, int>
     */
    public const QUARTERS = [1, 2, 3, 4];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'year' => 'integer',
            'quarter' => 'integer',
            'planned_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
        ];
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
     * "Q3" - the label, not a computation.
     */
    public function quarterLabel(): string
    {
        return 'Q'.$this->quarter;
    }
}
