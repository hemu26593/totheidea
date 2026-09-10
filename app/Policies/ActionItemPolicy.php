<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActionItem;
use App\Models\User;

/**
 * Authorization for the action list.
 */
class ActionItemPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('action_items.view');
    }

    public function view(User $actor, ActionItem $item): bool
    {
        return $actor->can('action_items.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('action_items.manage');
    }

    public function update(User $actor, ActionItem $item): bool
    {
        return $actor->can('action_items.manage');
    }

    public function complete(User $actor, ActionItem $item): bool
    {
        return $actor->can('action_items.manage');
    }

    /**
     * Never removed - an abandoned commitment is dropped, not deleted.
     */
    public function delete(User $actor, ActionItem $item): bool
    {
        return false;
    }
}
