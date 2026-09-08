<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionInstance;
use App\Models\User;

/**
 * Authorization for a session as delivered to a batch.
 *
 * Note what is NOT here: which batch this actor may touch. A policy that
 * trusted a request-supplied batch or customer id would authorise the wrong
 * record perfectly correctly, so SessionSchedulingService verifies the batch
 * and the program instead.
 */
class SessionInstancePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sessions.view');
    }

    public function view(User $actor, SessionInstance $instance): bool
    {
        return $actor->can('sessions.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sessions.create');
    }

    public function update(User $actor, SessionInstance $instance): bool
    {
        return $actor->can('sessions.edit');
    }

    public function complete(User $actor, SessionInstance $instance): bool
    {
        return $actor->can('sessions.complete');
    }

    public function cancel(User $actor, SessionInstance $instance): bool
    {
        return $actor->can('sessions.edit');
    }

    /**
     * Cancelled, never deleted.
     *
     * This refusal is not the whole guarantee: 'delete' is a guarded ability
     * so Gate::before defers to it even for a Super Admin, but the absolute
     * rule lives on the model, which throws on deleting().
     */
    public function delete(User $actor, SessionInstance $instance): bool
    {
        return false;
    }
}
