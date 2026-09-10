<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FundPlan;
use App\Models\User;

/**
 * Authorization for the monthly fund plan.
 *
 * APPROVAL IS ITS OWN PERMISSION. Staff holds fund_plans.manage and can build
 * a month's plan; it does not hold fund_plans.approve. FundPlanService refuses
 * the same way, so approval cannot happen by a caller forgetting to
 * authorize.
 */
class FundPlanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('fund_plans.view');
    }

    public function view(User $actor, FundPlan $plan): bool
    {
        return $actor->can('fund_plans.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('fund_plans.manage');
    }

    public function update(User $actor, FundPlan $plan): bool
    {
        return $actor->can('fund_plans.manage');
    }

    public function approve(User $actor, FundPlan $plan): bool
    {
        return $actor->can('fund_plans.approve');
    }

    /**
     * Never removed. Each month is its own record of what was planned.
     */
    public function delete(User $actor, FundPlan $plan): bool
    {
        return false;
    }
}
