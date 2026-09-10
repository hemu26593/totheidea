<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

class BatchPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('batches.view');
    }

    public function view(User $actor, Batch $batch): bool
    {
        return $actor->can('batches.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('batches.create');
    }

    public function update(User $actor, Batch $batch): bool
    {
        return $actor->can('batches.edit');
    }

    public function archive(User $actor, Batch $batch): bool
    {
        return $actor->can('batches.archive');
    }

    /**
     * A batch anchors a whole cohort's history. It is archived, never deleted.
     */
    public function delete(User $actor, Batch $batch): bool
    {
        return false;
    }
}
