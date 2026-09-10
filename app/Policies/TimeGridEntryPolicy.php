<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TimeGridEntry;
use App\Models\User;

/**
 * Authorization for quarterly strategic allocation.
 *
 * There is one permission, time_grid.manage, and viewing rides on
 * day_plans.view - the two instruments appear together in the participant
 * workspace, and a role trusted to see one is trusted to see the other. They
 * remain separate for WRITING, which is where the distinction bites.
 */
class TimeGridEntryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('day_plans.view');
    }

    public function view(User $actor, TimeGridEntry $entry): bool
    {
        return $actor->can('day_plans.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('time_grid.manage');
    }

    public function update(User $actor, TimeGridEntry $entry): bool
    {
        return $actor->can('time_grid.manage');
    }

    public function delete(User $actor, TimeGridEntry $entry): bool
    {
        return false;
    }
}
