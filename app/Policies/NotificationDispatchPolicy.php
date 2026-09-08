<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NotificationDispatch;
use App\Models\User;

/**
 * Authorization for the dispatch log.
 *
 * Reading it is notifications.view; changing anything about the system's
 * notification behaviour is notifications.manage, which Staff does not hold -
 * what a customer receives is not Staff's to change.
 *
 * No create ability: dispatches are written by the scheduler, never by a
 * person. No update: the outcome is the queue's to record.
 */
class NotificationDispatchPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('notifications.view');
    }

    public function view(User $actor, NotificationDispatch $dispatch): bool
    {
        return $actor->can('notifications.view');
    }

    /**
     * Requeuing a failed send is a management action, not a read.
     */
    public function manage(User $actor, NotificationDispatch $dispatch): bool
    {
        return $actor->can('notifications.manage');
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, NotificationDispatch $dispatch): bool
    {
        return false;
    }

    /**
     * Append-only. The model enforces the absolute form, because 'delete' is a
     * guarded ability but the record's value is that it cannot be tidied away.
     */
    public function delete(User $actor, NotificationDispatch $dispatch): bool
    {
        return false;
    }
}
