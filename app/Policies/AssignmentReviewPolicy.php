<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssignmentReview;
use App\Models\User;

/**
 * Authorization for the decision history.
 *
 * Every write ability refuses. A review is appended by AssignmentReviewService
 * and never touched again - and because 'update' is NOT one of
 * authorization.guarded_abilities, Gate::before would grant it to a Super
 * Admin regardless of what this policy says. The immutability guarantee
 * therefore lives on the model, which throws on updating() and deleting().
 * This policy states the intent; the model enforces it.
 */
class AssignmentReviewPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('assignments.view');
    }

    public function view(User $actor, AssignmentReview $review): bool
    {
        return $actor->can('assignments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('assignments.complete');
    }

    public function update(User $actor, AssignmentReview $review): bool
    {
        return false;
    }

    public function delete(User $actor, AssignmentReview $review): bool
    {
        return false;
    }
}
