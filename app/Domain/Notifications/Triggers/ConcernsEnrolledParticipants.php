<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Shared eligibility for the triggers that address participants.
 *
 * "Participant" means an ACTIVE enrolment. A withdrawn or completed enrolment
 * is nobody's audience: reminding someone who has left the programme about its
 * deadlines is the behaviour that gets a channel muted.
 */
trait ConcernsEnrolledParticipants
{
    /**
     * @return Collection<int, Enrollment>
     */
    protected function activeEnrollmentsInBatch(int $batchId): Collection
    {
        return Enrollment::query()
            ->where('batch_id', $batchId)
            ->where('status', 'enrolled')
            ->orderBy('id')
            ->get();
    }
}
