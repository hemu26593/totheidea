<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

/**
 * Authorization for enrolments - the ownership spine.
 *
 * Enrolment records are reached through a customer, so viewing follows
 * customers.view and administration follows batches.*.
 */
class EnrollmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, Enrollment $enrollment): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('batches.edit');
    }

    public function update(User $actor, Enrollment $enrollment): bool
    {
        return $actor->can('batches.edit');
    }

    /**
     * Enrolments are never removed - a withdrawn enrolment is a status, and
     * its data stays available.
     */
    public function delete(User $actor, Enrollment $enrollment): bool
    {
        return false;
    }
}
