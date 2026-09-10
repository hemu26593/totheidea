<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MmdTarget;
use App\Models\User;

/**
 * Authorization for targets.
 *
 * SETTING A TARGET IS NOT RECORDING A FIGURE. Staff holds mmd.manage and can
 * record and amend the daily figures; it does not hold mmd.set_targets,
 * because setting the number a business is measured against is an
 * approval-shaped act. That line was drawn in the Phase 0 matrix and is
 * preserved exactly here.
 */
class MmdTargetPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('mmd.view');
    }

    public function view(User $actor, MmdTarget $target): bool
    {
        return $actor->can('mmd.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('mmd.set_targets');
    }

    public function update(User $actor, MmdTarget $target): bool
    {
        return $actor->can('mmd.set_targets');
    }

    public function delete(User $actor, MmdTarget $target): bool
    {
        return false;
    }
}
