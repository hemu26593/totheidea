<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

/**
 * Authorization for notes.
 *
 * view() is the authorization boundary for the consultant's private notes:
 * an internal note requires notes.view_internal, a customer-visible one only
 * requires customers.view.
 *
 * This policy is the check for a single note the caller already holds. It is
 * NOT the only defence - NoteService filters internal notes out of collection
 * reads, and excludes them structurally from every external path. A note that
 * is never loaded cannot be leaked by a forgotten condition.
 */
class NotePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, Note $note): bool
    {
        if ($note->is_internal) {
            return $actor->can('notes.view_internal');
        }

        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('notes.manage');
    }

    public function update(User $actor, Note $note): bool
    {
        return $actor->can('notes.manage');
    }

    /**
     * Changing a private note into a customer-visible one is a disclosure
     * decision, so it needs the internal-notes permission as well.
     */
    public function setVisibility(User $actor, Note $note): bool
    {
        return $actor->can('notes.manage') && $actor->can('notes.view_internal');
    }

    public function archive(User $actor, Note $note): bool
    {
        return $actor->can('notes.manage');
    }

    public function delete(User $actor, Note $note): bool
    {
        return false;
    }
}
