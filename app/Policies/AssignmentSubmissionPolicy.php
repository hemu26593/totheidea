<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;

/**
 * Authorization for submitted work.
 *
 * Ownership is NOT resolved here. Which participant's work an actor may reach
 * is decided by AssignmentSubmissionService, which asserts that the assignment
 * instance and the enrolment resolve to the same batch (I4) and that the
 * redundant customer_id came from the enrolment (I15).
 */
class AssignmentSubmissionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('assignments.view');
    }

    public function view(User $actor, AssignmentSubmission $submission): bool
    {
        return $actor->can('assignments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('assignments.edit');
    }

    public function update(User $actor, AssignmentSubmission $submission): bool
    {
        return $actor->can('assignments.edit');
    }

    /**
     * Accepting and returning is its own capability: it is the decision that
     * completes a piece of the programme, not an edit.
     */
    public function review(User $actor, AssignmentSubmission $submission): bool
    {
        return $actor->can('assignments.complete');
    }

    public function delete(User $actor, AssignmentSubmission $submission): bool
    {
        return false;
    }
}
