<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MmdEntry;
use App\Models\User;

/**
 * Authorization for the daily business figures.
 */
class MmdEntryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('mmd.view');
    }

    public function view(User $actor, MmdEntry $entry): bool
    {
        return $actor->can('mmd.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('mmd.manage');
    }

    public function update(User $actor, MmdEntry $entry): bool
    {
        return $actor->can('mmd.manage');
    }

    public function amend(User $actor, MmdEntry $entry): bool
    {
        return $actor->can('mmd.manage');
    }

    /**
     * Amended with audit, never deleted.
     */
    public function delete(User $actor, MmdEntry $entry): bool
    {
        return false;
    }
}
