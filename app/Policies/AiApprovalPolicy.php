<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiApproval;
use App\Models\AiGeneration;
use App\Models\User;

/**
 * Authorization for the approval RECORD.
 *
 * The decision itself is authorised on the generation - `approve` and
 * `reject` live on AiGenerationPolicy, because the ability string is what
 * authorization.guarded_abilities lists and $user->can('approve', $generation)
 * is what a caller naturally writes. This class governs the row that results:
 * who may read it, and the fact that nobody may change or remove it.
 *
 * create() is the one decision-shaped ability here, reached as
 * $user->can('create', [AiApproval::class, $generation]). It applies the same
 * self-approval refusal, so the two entry points cannot disagree.
 */
class AiApprovalPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('ai.analysis.view');
    }

    public function view(User $actor, AiApproval $approval): bool
    {
        return $actor->can('ai.analysis.view');
    }

    /**
     * Recording a decision on one generation.
     *
     * Two conditions, and both are necessary. The permission says this actor
     * is the kind of person who reviews AI output; the generator check says
     * this particular generation is not their own.
     */
    public function create(User $actor, AiGeneration $generation): bool
    {
        if ($generation->wasGeneratedBy($actor)) {
            return false;
        }

        return $actor->can('ai.forms.approve');
    }

    /**
     * A decision is immutable. Enforced on the model, because `update` is not
     * a guarded ability.
     */
    public function update(User $actor, AiApproval $approval): bool
    {
        return false;
    }

    /**
     * Never. It is the record of who signed off.
     */
    public function delete(User $actor, AiApproval $approval): bool
    {
        return false;
    }
}
