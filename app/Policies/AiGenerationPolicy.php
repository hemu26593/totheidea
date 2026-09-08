<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use App\Models\User;

/**
 * Authorization for asking a model for something, and for reading what it
 * said.
 *
 * THE PERMISSION DEPENDS ON THE PURPOSE, and the gap is deliberate. Drafting
 * a form changes the instrument every participant answers, so it needs
 * ai.forms.generate. Producing a narrative or a summary is analysis and needs
 * ai.analysis.generate. Staff hold neither - they hold ai.analysis.view, so
 * they can read what was produced and cannot commission it.
 *
 * WHICH BUSINESS'S GENERATION AN ACTOR MAY REACH IS NOT DECIDED HERE. A
 * policy that trusted a request-supplied customer id would authorise the
 * wrong business's context perfectly correctly. Isolation is structural
 * instead: ai_generations.customer_id is NOT NULL, CustomerContextAssembler
 * scopes every query by it, and AiGenerationService refuses a context
 * assembled for anyone else.
 */
class AiGenerationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('ai.analysis.view');
    }

    public function view(User $actor, AiGeneration $generation): bool
    {
        return $actor->can('ai.analysis.view');
    }

    /**
     * Commissioning a generation for a purpose.
     */
    public function generate(User $actor, AiPurpose $purpose): bool
    {
        return match ($purpose) {
            AiPurpose::FormDraft => $actor->can('ai.forms.generate'),
            AiPurpose::Narrative, AiPurpose::Summary => $actor->can('ai.analysis.generate'),
        };
    }

    /**
     * Turning validated output into a draft form version. The same permission
     * as commissioning it: the draft is the thing that was commissioned.
     */
    public function draft(User $actor, AiGeneration $generation): bool
    {
        return $actor->can('ai.forms.generate');
    }

    public function create(User $actor): bool
    {
        return $actor->can('ai.forms.generate') || $actor->can('ai.analysis.generate');
    }

    /**
     * DECIDING ON AI OUTPUT. This is the method the guarded-ability list
     * exists for.
     *
     * `approve` is listed in authorization.guarded_abilities, so Gate::before
     * returns null for it and a Super Admin falls through to this policy
     * instead of being granted the ability outright. Without that entry, this
     * method would never be called for the one actor most able to do damage
     * with it.
     *
     * The refusal is the self-approval invariant (I9): whoever generated an
     * artifact does not decide on it. Two people looked at it, or it did not
     * ship.
     *
     * A POLICY IS NOT THE WHOLE ENFORCEMENT. It governs the paths that
     * consult it. AiApprovalService repeats the check for programmatic
     * callers, and AiApproval's creating guard repeats it for direct writes,
     * so no path records a self-approval.
     */
    public function approve(User $actor, AiGeneration $generation): bool
    {
        if ($generation->wasGeneratedBy($actor)) {
            return false;
        }

        return $actor->can('ai.forms.approve');
    }

    /**
     * Rejecting carries the same conditions as approving: it is the same act
     * of judgement, and a generator marking their own output rejected would
     * be just as much a single pair of eyes.
     */
    public function reject(User $actor, AiGeneration $generation): bool
    {
        return $this->approve($actor, $generation);
    }

    /**
     * A generation records what was asked and what the model saw. Those are
     * fixed; the model enforces it, since `update` is unguarded and
     * Gate::before would hand it to a Super Admin.
     */
    public function update(User $actor, AiGeneration $generation): bool
    {
        return false;
    }

    /**
     * Never. A failed generation is provenance too.
     */
    public function delete(User $actor, AiGeneration $generation): bool
    {
        return false;
    }
}
