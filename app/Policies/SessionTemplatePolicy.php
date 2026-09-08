<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionTemplate;
use App\Models\User;

/**
 * Authorization for the curriculum.
 *
 * A session template is not customer-owned, so there is no isolation to
 * resolve here - only the capability to author a curriculum.
 */
class SessionTemplatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sessions.view');
    }

    public function view(User $actor, SessionTemplate $template): bool
    {
        return $actor->can('sessions.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sessions.create');
    }

    public function update(User $actor, SessionTemplate $template): bool
    {
        return $actor->can('sessions.edit');
    }

    public function archive(User $actor, SessionTemplate $template): bool
    {
        return $actor->can('sessions.archive');
    }

    /**
     * Archive-only. Delivered sessions point at this row.
     */
    public function delete(User $actor, SessionTemplate $template): bool
    {
        return false;
    }
}
