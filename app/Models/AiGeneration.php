<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use Database\Factories\AiGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * One AI invocation and everything needed to explain it later.
 *
 * customer_id IS THE ISOLATION BOUNDARY. BelongsToCustomer makes it required
 * on insert and permanent afterwards, so a generation cannot be created
 * without naming whose business it concerns and cannot later be reassigned to
 * another.
 *
 * input_context IS WRITE-ONCE PROVENANCE. It records what the model actually
 * saw. Nothing in this application reads a figure back out of it - the same
 * rule report artifacts follow, and for the same reason: a snapshot answers
 * "what did we show?", never "what is true now?". Both are asserted by tests.
 *
 * NEVER DELETED. A failed generation is retained; a failure is provenance too
 * and is the case most worth reviewing.
 *
 * NO ACTOR TRIPLE. generated_by is NOT NULL and is always an internal user.
 * An external access grant can never invoke AI, which is why there is no
 * source / access_grant_id pair here and a test asserts the columns' absence.
 *
 * Mass-assignment note: nothing is fillable. Rows are written by
 * AiGenerationService and settled by AiApprovalService.
 */
#[Fillable([])]
class AiGeneration extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<AiGenerationFactory> */
    use HasFactory;

    /**
     * The columns that describe the invocation itself.
     *
     * They are fixed the moment the row exists. Only the lifecycle - status,
     * the outcome fields and the resulting draft - moves afterwards, because
     * a generation whose provider call has not returned yet is exactly a row
     * whose outcome is not known.
     *
     * @var array<int, string>
     */
    private const IDENTITY = [
        'customer_id',
        'ai_prompt_version_id',
        'purpose',
        'input_context',
        'generated_by',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AiPurpose::class,
            'status' => AiGenerationStatus::class,
            'input_context' => 'array',
            'validated_output' => 'array',
            'generated_at' => 'datetime',
            'tokens_used' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AiGeneration $generation): void {
            $rewritten = array_intersect(array_keys($generation->getDirty()), self::IDENTITY);

            if ($rewritten !== []) {
                throw new RuntimeException(sprintf(
                    'A generation records what was asked and what the model saw (%s). '
                    .'Those are fixed; re-running produces a new generation.',
                    implode(', ', $rewritten),
                ));
            }
        });

        static::deleting(function (AiGeneration $generation): never {
            throw new RuntimeException(
                'An AI generation is never deleted. A failed generation is provenance too.'
            );
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'ai_prompt_version_id');
    }

    /**
     * The DRAFT this generation produced, if it produced one.
     */
    public function resultingFormVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'resulting_form_version_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * At most one, by database constraint. A reversal is a new generation.
     */
    public function approval(): HasOne
    {
        return $this->hasOne(AiApproval::class);
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === AiGenerationStatus::AwaitingApproval;
    }

    public function isDecided(): bool
    {
        return $this->status->isDecided();
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', AiGenerationStatus::AwaitingApproval->value);
    }

    /**
     * Whether this user may not decide on this generation.
     *
     * The self-approval invariant (I9) expressed on the record itself, so
     * every caller - service, policy, UI - asks the same question of the same
     * object rather than each re-deriving the comparison.
     */
    public function wasGeneratedBy(User $user): bool
    {
        return (int) $this->generated_by === (int) $user->getKey();
    }
}
