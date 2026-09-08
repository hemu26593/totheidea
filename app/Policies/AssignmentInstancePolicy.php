<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssignmentInstance;
use App\Models\User;

/**
 * Authorization for a released assignment.
 *
 * Releasing is assignments.create rather than assignments.edit: it is the act
 * that makes work visible to a whole cohort and starts the reminder clock,
 * which is a different responsibility from correcting a typo in a draft.
 */
class AssignmentInstancePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('assignments.view');
    }

    public function view(User $actor, AssignmentInstance $instance): bool
    {
        return $actor->can('assignments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('assignments.create');
    }

    public function release(User $actor, AssignmentInstance $instance): bool
    {
        return $actor->can('assignments.create');
    }

    public function update(User $actor, AssignmentInstance $instance): bool
    {
        return $actor->can('assignments.edit');
    }

    public function close(User $actor, AssignmentInstance $instance): bool
    {
        return $actor->can('assignments.edit');
    }

    /**
     * Closed, never deleted. The model enforces the absolute form.
     */
    public function delete(User $actor, AssignmentInstance $instance): bool
    {
        return false;
    }
}
