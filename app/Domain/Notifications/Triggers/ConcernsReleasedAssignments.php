<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Shared eligibility for the two assignment triggers.
 *
 * Both need the same thing: who, in this batch, has not handed this in.
 * "Handed in" means submitted or accepted - a returned submission is
 * outstanding again, which is exactly why it should still be reminded about.
 */
trait ConcernsReleasedAssignments
{
    use ConcernsEnrolledParticipants;

    /**
     * @return Collection<int, Enrollment>
     */
    protected function enrollmentsAwaiting(AssignmentInstance $instance): Collection
    {
        $sessionInstance = $instance->sessionInstance()->first();

        if ($sessionInstance === null) {
            return collect();
        }

        $settled = AssignmentSubmission::query()
            ->where('assignment_instance_id', $instance->getKey())
            ->whereIn('status', [
                AssignmentSubmission::STATUS_SUBMITTED,
                AssignmentSubmission::STATUS_ACCEPTED,
            ])
            ->pluck('enrollment_id')
            ->all();

        return $this->activeEnrollmentsInBatch((int) $sessionInstance->batch_id)
            ->reject(fn (Enrollment $e): bool => in_array($e->getKey(), $settled, false))
            ->values();
    }
}
