<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Position;
use App\Models\User;

/**
 * Authorization for the participant's org chart.
 *
 * One permission covers reading and writing - positions.manage - because the
 * Phase 0 matrix draws no line between them here, and inventing a
 * positions.view would grant something nobody decided to grant.
 *
 * Which business's chart an actor may touch is NOT decided here. A policy that
 * trusted a request-supplied customer id would authorise the wrong record
 * perfectly correctly, so PositionService resolves the customer and enforces
 * I13 instead.
 */
class PositionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('positions.manage');
    }

    public function view(User $actor, Position $position): bool
    {
        return $actor->can('positions.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->can('positions.manage');
    }

    public function update(User $actor, Position $position): bool
    {
        return $actor->can('positions.manage');
    }

    public function archive(User $actor, Position $position): bool
    {
        return $actor->can('positions.manage');
    }

    /**
     * Archive-only. The model enforces the absolute form: acknowledgements
     * point at positions, and a compliance record that cannot say who signed
     * is not a record.
     */
    public function delete(User $actor, Position $position): bool
    {
        return false;
    }
}
