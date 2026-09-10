<?php

declare(strict_types=1);

namespace App\Domain\Sessions;

use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Models\Enrollment;
use App\Models\SessionAttendance;

/**
 * The two attendance figures the SOW asks for.
 *
 * BOTH ARE COMPUTED IN PHP AND NEVER STORED. A stored percentage goes stale
 * the moment a mark is amended, and this is a contractual completion figure.
 * Neither is ever produced by a model: Laravel is authoritative for every
 * number a decision rests on.
 *
 *   current %        = credited / counted
 *   still reachable? = (credited + sessions_remaining) / total >= 0.90
 *
 * The second is "the warning goes out when a participant can no longer reach
 * it". It fires the moment the BEST POSSIBLE final percentage drops below the
 * threshold, which is strictly earlier than the current percentage doing so.
 *
 * The SHAPE of both formulas is settled. Their INPUTS are not: how `late` and
 * `excused` weigh is [CLIENT DECISION - ATTENDANCE WEIGHTING], which is why
 * this class takes an AttendanceWeighting rather than deciding for itself.
 * That contract has no implementation and no container binding, so
 * constructing this calculator fails until the client answers.
 */
class AttendanceCalculator
{
    /**
     * The 90% rule. This number is from the SOW, not an assumption.
     */
    public const COMPLETION_THRESHOLD = 0.90;

    public function __construct(private readonly AttendanceWeighting $weighting) {}

    /**
     * Attendance so far, over the sessions held so far.
     *
     * Null when nothing counts yet - a participant with no counted sessions
     * has no percentage, and reporting 0% would read as "attended nothing".
     */
    public function currentPercentage(Enrollment $enrollment): ?float
    {
        $marks = $this->marksFor($enrollment);

        $counted = 0;
        $credited = 0.0;

        foreach ($marks as $status) {
            if (! $this->weighting->countsTowardTotal($status)) {
                continue;
            }

            $counted++;
            $credited += $this->weighting->attendanceCredit($status);
        }

        return $counted === 0 ? null : $credited / $counted;
    }

    /**
     * Can this participant still finish at or above the threshold?
     *
     * $totalSessions is the batch's full session count; anything not yet
     * marked is assumed attended, which is what makes this the BEST POSSIBLE
     * outcome rather than a projection.
     */
    public function isStillReachable(Enrollment $enrollment, int $totalSessions): bool
    {
        if ($totalSessions <= 0) {
            return true;
        }

        $marks = $this->marksFor($enrollment);

        $credited = 0.0;
        $excludedFromTotal = 0;

        foreach ($marks as $status) {
            if (! $this->weighting->countsTowardTotal($status)) {
                $excludedFromTotal++;

                continue;
            }

            $credited += $this->weighting->attendanceCredit($status);
        }

        $total = $totalSessions - $excludedFromTotal;

        if ($total <= 0) {
            return true;
        }

        $remaining = max(0, $total - (count($marks) - $excludedFromTotal));

        return ($credited + $remaining) / $total >= self::COMPLETION_THRESHOLD;
    }

    /**
     * @return array<int, string>
     */
    private function marksFor(Enrollment $enrollment): array
    {
        return SessionAttendance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->pluck('status')
            ->all();
    }
}
