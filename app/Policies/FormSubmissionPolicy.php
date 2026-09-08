<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;

/**
 * Authorization for submissions.
 *
 * Ownership is NOT resolved here. A policy that trusted a request-supplied
 * customer_id would authorise the wrong record perfectly correctly, so
 * SubmissionService verifies the enrolment and customer instead.
 */
class FormSubmissionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('assessments.view');
    }

    public function view(User $actor, FormSubmission $submission): bool
    {
        return $actor->can('assessments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('assessments.manage');
    }

    public function update(User $actor, FormSubmission $submission): bool
    {
        return $actor->can('assessments.manage');
    }

    public function review(User $actor, FormSubmission $submission): bool
    {
        return $actor->can('assessments.manage');
    }

    /**
     * Submissions are amended with audit, never deleted.
     */
    public function delete(User $actor, FormSubmission $submission): bool
    {
        return false;
    }
}
