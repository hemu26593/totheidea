<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssignmentTemplate;
use App\Models\User;

/**
 * Authorization for reusable assignment definitions. Curriculum, not customer
 * data.
 */
class AssignmentTemplatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('assignments.view');
    }

    public function view(User $actor, AssignmentTemplate $template): bool
    {
        return $actor->can('assignments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('assignments.create');
    }

    public function update(User $actor, AssignmentTemplate $template): bool
    {
        return $actor->can('assignments.edit');
    }

    public function archive(User $actor, AssignmentTemplate $template): bool
    {
        return $actor->can('assignments.archive');
    }

    public function delete(User $actor, AssignmentTemplate $template): bool
    {
        return false;
    }
}
