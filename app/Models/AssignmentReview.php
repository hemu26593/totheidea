<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssignmentReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One accept/return decision, with its remark - a HISTORICAL RECORD.
 *
 * APPEND-ONLY AND IMMUTABLE. A changed mind is a new review on a new attempt,
 * never an edit: editing one would rewrite what a consultant actually told a
 * participant.
 *
 * The guards are on the model, not in AssignmentReviewPolicy. Gate::before
 * grants a Super Admin every ability outside
 * authorization.guarded_abilities, and 'update' is not one of them, so a
 * policy alone would not make this absolute.
 *
 * No actor triple: reviewed_by is NOT NULL and always an internal user. An
 * external grant can never write here by construction.
 */
#[Fillable([])]
class AssignmentReview extends Model
{
    /** @use HasFactory<AssignmentReviewFactory> */
    use HasFactory;

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_RETURNED = 'returned';

    /** @var array<int, string> */
    public const DECISIONS = [
        self::DECISION_ACCEPTED,
        self::DECISION_RETURNED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'An assignment review is immutable. A changed decision is a new review on a new '
                .'attempt.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'An assignment review cannot be deleted. It is the history of the decision.'
            );
        });
    }

    public function assignmentSubmission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function wasAccepted(): bool
    {
        return $this->decision === self::DECISION_ACCEPTED;
    }
}
