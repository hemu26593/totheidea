<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Authorization for document attachments.
 *
 * Note what this policy does NOT do: it does not resolve which customer the
 * document belongs to. That is the subject's job and it happens in
 * DocumentService, because a policy that trusted a request-supplied
 * customer_id would authorise the wrong record perfectly correctly.
 */
class DocumentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, Document $document): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('documents.manage');
    }

    public function update(User $actor, Document $document): bool
    {
        return $actor->can('documents.manage');
    }

    public function archive(User $actor, Document $document): bool
    {
        return $actor->can('documents.manage');
    }

    /**
     * Archive-only. A delivered document must stay retrievable.
     */
    public function delete(User $actor, Document $document): bool
    {
        return false;
    }
}
