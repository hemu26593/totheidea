<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubmissionScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A computed NUMERIC score. APPEND-ONLY.
 *
 * The diagnostic report is generated before Session 1 and handed over, so a
 * later correction to a scoring rule must not retroactively change what was
 * delivered. Recomputation appends a new row; the model refuses updates and
 * deletes outright, exactly as audit_logs does.
 *
 * This table NEVER holds the Average / Good / Better / Best category. It is
 * numeric. The category is an answer, not a score, and copying it here would
 * create a second source of truth and invite the forbidden numeric mapping.
 *
 * Mass-assignment note: nothing is fillable. ScoringService is the only
 * writer, and it uses forceFill.
 */
#[Fillable([])]
class SubmissionScore extends Model
{
    /** @use HasFactory<SubmissionScoreFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * Open only while ScoringService is writing.
     *
     * Laravel calculates the authoritative numbers. Nothing else may write
     * them - not a Livewire component, not a controller, not a model
     * observer, and above all not AI. This flag makes that structural rather
     * than a convention someone can forget.
     */
    private static bool $writable = false;

    /**
     * Run a closure with score writing permitted.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $writer
     * @return TReturn
     */
    public static function writeThrough(callable $writer): mixed
    {
        self::$writable = true;

        try {
            return $writer();
        } finally {
            self::$writable = false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (! self::$writable) {
                throw new RuntimeException(
                    'submission_scores may only be written by ScoringService. '
                    .'Laravel is authoritative for scores; nothing else computes them.'
                );
            }
        });

        static::updating(function (): never {
            throw new RuntimeException(
                'Submission scores are append-only. Recompute to append a new row instead.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException('A submission score cannot be deleted.');
        });
    }

    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class);
    }

    public function skillArea(): BelongsTo
    {
        return $this->belongsTo(SkillArea::class);
    }

    public function isOverall(): bool
    {
        return $this->score_type === 'overall';
    }
}
