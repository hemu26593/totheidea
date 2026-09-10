<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\PlanningType;
use Database\Factories\FundPlanLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned/actual amount within a month's plan.
 *
 * Three independent dimensions, each carrying its own confirmed vocabulary:
 *
 *   section             where the line sits
 *   planning_type       BFP | FBP | EBP | SM - how it was planned
 *   due_classification  CD | LD | LLD - how aged the receivable is
 *
 * The terms are the client's and are used exactly as given. Nothing in this
 * codebase expands, renames or reinterprets them.
 *
 * week_number: NULL is a month-level line, 1-5 a weekly one. That is how
 * weekly money in and out is held without a third table.
 */
#[Fillable([])]
class FundPlanLine extends Model
{
    /** @use HasFactory<FundPlanLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => FundPlanSection::class,
            'planning_type' => PlanningType::class,
            'due_classification' => DueClassification::class,
            'week_number' => 'integer',
            'planned_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function fundPlan(): BelongsTo
    {
        return $this->belongsTo(FundPlan::class);
    }

    /**
     * A weekly line rather than a month-level one.
     */
    public function isWeekly(): bool
    {
        return $this->week_number !== null;
    }
}
