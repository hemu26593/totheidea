<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HrPolicyAcknowledgement;
use App\Models\User;

/**
 * Authorization for sign-offs.
 *
 * Reading and recording ride on hr_policies.manage: an acknowledgement belongs
 * to its policy and there is no separate acknowledgements permission in the
 * Phase 0 matrix. Inventing one would grant something nobody decided.
 *
 * Every write ability refuses. A sign-off is appended once and never touched
 * again - and because 'update' is not among authorization.guarded_abilities,
 * Gate::before would grant it to a Super Admin regardless of what this class
 * says. The guarantee therefore lives on the model, which throws. This policy
 * states the intent; the model enforces it.
 */
class HrPolicyAcknowledgementPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function view(User $actor, HrPolicyAcknowledgement $acknowledgement): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function update(User $actor, HrPolicyAcknowledgement $acknowledgement): bool
    {
        return false;
    }

    public function delete(User $actor, HrPolicyAcknowledgement $acknowledgement): bool
    {
        return false;
    }
}
