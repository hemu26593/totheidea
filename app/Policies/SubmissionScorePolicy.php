<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SubmissionScore;
use App\Models\User;

/**
 * Authorization for computed scores.
 *
 * Scores are read, never authored by a person. There is no create() or
 * update() ability that grants anyone the right to write one: ScoringService
 * is the only writer, and the model refuses everything else.
 */
class SubmissionScorePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('assessments.view');
    }

    public function view(User $actor, SubmissionScore $score): bool
    {
        return $actor->can('assessments.view');
    }

    /**
     * Nobody authors a score by hand. Laravel computes them.
     */
    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, SubmissionScore $score): bool
    {
        return false;
    }

    public function delete(User $actor, SubmissionScore $score): bool
    {
        return false;
    }
}
