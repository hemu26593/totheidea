<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DayPlanItem;
use App\Models\User;

/**
 * Authorization for the daily planner.
 *
 * Ownership is not resolved here: which participant's plan an actor may reach
 * is decided by DayPlanService, which takes the enrolment and copies
 * customer_id from it. A policy that trusted a request-supplied enrolment id
 * would authorise the wrong record perfectly correctly.
 */
class DayPlanItemPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('day_plans.view');
    }

    public function view(User $actor, DayPlanItem $item): bool
    {
        return $actor->can('day_plans.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('day_plans.manage');
    }

    public function update(User $actor, DayPlanItem $item): bool
    {
        return $actor->can('day_plans.manage');
    }

    public function complete(User $actor, DayPlanItem $item): bool
    {
        return $actor->can('day_plans.manage');
    }

    /**
     * Never deleted. The model enforces the absolute form, because 'delete' is
     * guarded but the record's value is that a slipped task cannot be tidied
     * away.
     */
    public function delete(User $actor, DayPlanItem $item): bool
    {
        return false;
    }
}
