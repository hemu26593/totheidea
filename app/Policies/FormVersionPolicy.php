<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormVersion;
use App\Models\User;

/**
 * Authorization for form versions.
 *
 * update() refuses a published version, but note that this policy is NOT
 * where that guarantee lives: 'update' is not a guarded ability, so
 * Gate::before short-circuits it for a Super Admin. Immutability after
 * publication is enforced on the FormVersion model, which nothing bypasses.
 * The policy is the 403 for HTTP callers; the model is the integrity
 * guarantee.
 */
class FormVersionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('forms.view');
    }

    public function view(User $actor, FormVersion $version): bool
    {
        return $actor->can('forms.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('forms.create');
    }

    public function update(User $actor, FormVersion $version): bool
    {
        return $version->isDraft() && $actor->can('forms.edit');
    }

    public function publish(User $actor, FormVersion $version): bool
    {
        return $version->isDraft() && $actor->can('forms.publish');
    }

    public function archive(User $actor, FormVersion $version): bool
    {
        return $actor->can('forms.archive');
    }

    /**
     * Never. Submissions bind to a version as the record of what was asked.
     */
    public function delete(User $actor, FormVersion $version): bool
    {
        return false;
    }
}
