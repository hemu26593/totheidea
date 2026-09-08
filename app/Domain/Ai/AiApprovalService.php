<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Forms\FormPublishingService;
use App\Enums\AiApprovalDecision;
use App\Enums\AiGenerationStatus;
use App\Enums\AuditAction;
use App\Models\AiApproval;
use App\Models\AiGeneration;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * APPROVE -> PUBLISH. The last two stages, and the invariant that separates
 * them from everything before.
 *
 * THE SELF-APPROVAL INVARIANT (I9). Whoever generated an artifact cannot
 * approve it. It is enforced in three places, and that is not redundancy for
 * its own sake - each covers a path the others do not:
 *
 *   1. AiApprovalPolicy, so the UI and any Gate::authorize call refuse. The
 *      ability is `approve`, which is listed in
 *      authorization.guarded_abilities, so Gate::before does NOT short-circuit
 *      it for a Super Admin. Without that entry a Super Admin would be granted
 *      the ability before the policy was ever consulted.
 *   2. This service, so every programmatic caller - a console command, a job,
 *      a future controller - hits the same refusal.
 *   3. AiApproval's creating guard, so even a direct model write cannot
 *      record a self-approval.
 *
 *   A policy alone would be a statement of intent. The model guard is what
 *   makes it absolute, which is what "this applies even to Super Admin" has
 *   to mean.
 *
 * PUBLISHING IS A CONSEQUENCE OF APPROVAL, NEVER OF GENERATION. An approved
 * form draft is published through the ordinary FormPublishingService, in the
 * same transaction, credited to the approver - the person who took
 * responsibility for it.
 */
class AiApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FormPublishingService $publisher,
    ) {}

    /**
     * Approve a generation, and publish whatever it drafted.
     */
    public function approve(AiGeneration $generation, User $decider, ?string $remark = null): AiApproval
    {
        return $this->decide($generation, $decider, AiApprovalDecision::Approved, $remark);
    }

    /**
     * Reject a generation. The draft it produced is left as a draft: it is
     * never published, and it is not deleted either, because what was
     * proposed and turned down is worth being able to look at.
     */
    public function reject(AiGeneration $generation, User $decider, ?string $remark = null): AiApproval
    {
        return $this->decide($generation, $decider, AiApprovalDecision::Rejected, $remark);
    }

    private function decide(
        AiGeneration $generation,
        User $decider,
        AiApprovalDecision $decision,
        ?string $remark,
    ): AiApproval {
        $this->assertDecidable($generation);
        $this->assertNotSelfApproval($generation, $decider);

        return DB::transaction(function () use ($generation, $decider, $decision, $remark): AiApproval {
            $approval = new AiApproval;
            $approval->forceFill([
                'ai_generation_id' => $generation->getKey(),
                'decision' => $decision,
                'decided_by' => $decider->getKey(),
                'decided_at' => now(),
                'remark' => $remark,
            ])->save();

            $generation->forceFill([
                'status' => $decision === AiApprovalDecision::Approved
                    ? AiGenerationStatus::Approved
                    : AiGenerationStatus::Rejected,
            ])->save();

            if ($decision === AiApprovalDecision::Approved) {
                $this->publishDraft($generation, $decider);
            }

            $this->audit->log(
                $decision === AiApprovalDecision::Approved ? AuditAction::AiApproved : AuditAction::AiRejected,
                $generation,
                ['status' => AiGenerationStatus::AwaitingApproval->value],
                [
                    'status' => $generation->status->value,
                    'decided_by' => (int) $decider->getKey(),
                    'customer_id' => (int) $generation->customer_id,
                ],
                $decider,
            );

            return $approval->refresh();
        });
    }

    /**
     * The draft becomes the live form, through the ordinary engine.
     *
     * Nothing special happens to an AI-authored version at this point: it
     * archives its predecessor, gains a published_at, and is answered and
     * scored by the same code as any other - which is the whole reason the
     * draft was built inside the form engine rather than beside it.
     */
    private function publishDraft(AiGeneration $generation, User $decider): void
    {
        $version = $generation->resultingFormVersion()->first();

        if ($version === null || ! $version->isDraft()) {
            return;
        }

        $this->publisher->publish($version, $decider);
    }

    private function assertDecidable(AiGeneration $generation): void
    {
        if ($generation->status !== AiGenerationStatus::AwaitingApproval) {
            throw new RuntimeException(sprintf(
                'Generation %d is [%s]. A decision is recorded only on a generation that has produced '
                .'something to review, and only once - a reversal is a new generation.',
                (int) $generation->getKey(),
                $generation->status->value,
            ));
        }
    }

    /**
     * Invariant I9. See the class docblock for why this exists in three
     * places rather than one.
     */
    private function assertNotSelfApproval(AiGeneration $generation, User $decider): void
    {
        if ($generation->wasGeneratedBy($decider)) {
            throw new RuntimeException(sprintf(
                'Refused: user %d generated AI generation %d and cannot decide on it. '
                .'AI output is reviewed by a second person - ADR-014, and it holds for a Super Admin too.',
                (int) $decider->getKey(),
                (int) $generation->getKey(),
            ));
        }
    }
}
