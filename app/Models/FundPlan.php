<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FundPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One month's fund plan for one enrolment.
 *
 * PLANNING, NOT ACCOUNTING. No ledger, no invoice, no payment, no bank
 * reconciliation. The plan records intention and outcome; it is not a book of
 * record for money that moved.
 *
 * NEVER REMOVED. UNIQUE (enrollment_id, year, month) is what makes each month
 * its own row set, so an approved March survives April being planned.
 *
 * Mass-assignment note: nothing is fillable. Approval in particular goes
 * through FundPlanService, which holds the permission check and the audit
 * entry.
 */
#[Fillable([])]
class FundPlan extends Model
{
    /** @use HasFactory<FundPlanFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'budget_total' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'A fund plan is never removed. Each month is its own record of what was planned.'
            );
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FundPlanLine::class)->orderBy('position')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
