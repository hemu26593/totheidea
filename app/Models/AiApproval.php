<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiApprovalDecision;
use Database\Factories\AiApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The human decision on one generation.
 *
 * A SEPARATE RECORD SO THE RULE IS EXPRESSIBLE. ADR-014 requires that whoever
 * generated an AI artifact cannot approve it. Comparing two acts needs two
 * records with two actors; a decided_by column on ai_generations would make
 * that a self-comparison on a single row.
 *
 * THE INVARIANT IS ENFORCED HERE, NOT ONLY IN A POLICY OR A SERVICE. `approve`
 * is in authorization.guarded_abilities so Gate::before does not short-circuit
 * AiApprovalPolicy for a Super Admin - but a policy still only governs the
 * paths that consult it. The creating guard below governs every path,
 * including a direct write, which is what "this applies even to Super Admin"
 * has to mean to be worth anything.
 *
 * IMMUTABLE. A reversal is a new generation, not a second or amended decision.
 */
#[Fillable([])]
class AiApproval extends Model
{
    /** @use HasFactory<AiApprovalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => AiApprovalDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiApproval $approval): void {
            $approval->assertDeciderIsNotTheGenerator();
        });

        static::updating(function (AiApproval $approval): never {
            throw new RuntimeException(
                'An approval decision is immutable. Reversing a decision means generating again, '
                .'so the original judgement and who made it stay on the record.'
            );
        });

        static::deleting(function (AiApproval $approval): never {
            throw new RuntimeException(
                'An approval decision is never deleted. It is the record of who signed off.'
            );
        });
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isApproved(): bool
    {
        return $this->decision === AiApprovalDecision::Approved;
    }

    /**
     * Invariant I9, cross-table and therefore not a database constraint.
     *
     * Refuses when the generation cannot be resolved, rather than assuming
     * the actors differ. An unresolvable generation is the one case where
     * guessing would be indistinguishable from the rule being off.
     */
    public function assertDeciderIsNotTheGenerator(): void
    {
        $generation = AiGeneration::query()->find($this->ai_generation_id);

        if ($generation === null) {
            throw new RuntimeException(
                'Refused: the generation being decided on cannot be resolved, so it is not '
                .'possible to establish that the approver is not the generator.'
            );
        }

        if ((int) $generation->generated_by === (int) $this->decided_by) {
            throw new RuntimeException(sprintf(
                'Refused: user %d generated AI generation %d and cannot approve it. '
                .'AI output is reviewed by a second person - ADR-014, and it holds for a Super Admin too.',
                (int) $this->decided_by,
                (int) $generation->getKey(),
            ));
        }
    }
}
