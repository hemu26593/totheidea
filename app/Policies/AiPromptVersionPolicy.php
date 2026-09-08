<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiPromptVersion;
use App\Models\User;

/**
 * Authorization for prompt authoring.
 *
 * A prompt version is what the model is told to do, so writing one is a more
 * consequential act than asking for a generation. It rides on
 * ai.forms.approve rather than ai.forms.generate: whoever can decide that AI
 * output is acceptable is the same person who should be deciding what it was
 * asked for.
 *
 * Staff hold only ai.analysis.view (Phase 0) and can therefore read prompts
 * and nothing else.
 */
class AiPromptVersionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('ai.analysis.view');
    }

    public function view(User $actor, AiPromptVersion $prompt): bool
    {
        return $actor->can('ai.analysis.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('ai.forms.approve');
    }

    /**
     * Publishing makes a prompt the one every subsequent generation uses.
     */
    public function publish(User $actor, AiPromptVersion $prompt): bool
    {
        return $actor->can('ai.forms.approve') && $prompt->isDraft();
    }

    /**
     * A published prompt is immutable. The model enforces the absolute form,
     * because `update` is not a guarded ability and Gate::before would
     * otherwise grant it to a Super Admin without consulting this class.
     */
    public function update(User $actor, AiPromptVersion $prompt): bool
    {
        return $actor->can('ai.forms.approve') && $prompt->isDraft();
    }

    /**
     * Never. Generations bind here as the record of what was asked.
     */
    public function delete(User $actor, AiPromptVersion $prompt): bool
    {
        return false;
    }
}
