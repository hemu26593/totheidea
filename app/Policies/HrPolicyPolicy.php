<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HrPolicy;
use App\Models\User;

/**
 * Authorization for the HR policy library.
 *
 * PUBLICATION IS ITS OWN PERMISSION. Staff holds hr_policies.manage and can
 * draft, revise and prepare a policy; it does not hold hr_policies.publish,
 * because putting a policy in force across someone's business is an
 * approval-shaped act. HrPolicyService refuses on the same permission, so
 * publication cannot happen through a caller that forgets to authorize.
 *
 * update() intentionally covers drafting only in spirit: the model refuses to
 * let published text change at all, whoever asks - including a Super Admin,
 * for whom Gate::before would otherwise grant 'update' without consulting this
 * class.
 */
class HrPolicyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function view(User $actor, HrPolicy $policy): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function update(User $actor, HrPolicy $policy): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function publish(User $actor, HrPolicy $policy): bool
    {
        return $actor->can('hr_policies.publish');
    }

    /**
     * Superseding replaces a policy in force, so it is drafting plus a state
     * change - not publication. The replacement still has to be published by
     * someone who may.
     */
    public function supersede(User $actor, HrPolicy $policy): bool
    {
        return $actor->can('hr_policies.manage');
    }

    public function archive(User $actor, HrPolicy $policy): bool
    {
        return $actor->can('hr_policies.manage');
    }

    /**
     * Archived or superseded, never deleted.
     */
    public function delete(User $actor, HrPolicy $policy): bool
    {
        return false;
    }
}
