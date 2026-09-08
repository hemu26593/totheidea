<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Contracts;

/**
 * How each attendance status weighs in the 90% completion rule.
 *
 * [CLIENT DECISION - ATTENDANCE WEIGHTING]
 *
 * THIS INTERFACE HAS NO IMPLEMENTATION AND IS DELIBERATELY NOT BOUND IN THE
 * CONTAINER. Resolving it fails, loudly, at the point of use.
 *
 * Two of the four statuses are settled: `present` counts toward the numerator,
 * `absent` does not. The other two are open, and the question has two
 * independent parts:
 *
 *   Numerator   - does `late` count as attended, partially, or not at all?
 *                 Does `excused`?
 *   Denominator - does an `excused` session stay in the total, or come out of
 *                 it? These are materially different: removing it RAISES the
 *                 percentage, counting it as absent LOWERS it.
 *
 * No default is assumed, and no plausible-sounding rule is used as a stand-in.
 * The 90% figure gates a warning sent to the participant and the consultant
 * and is a contractual completion measure, so this is not a config value with
 * a safe default - it is a business rule with no safe default.
 *
 * Everything else about attendance is built: storage, the four statuses,
 * validation, marking, double-marking prevention, amendment history, audit,
 * authorization and isolation. Only the weighting is unbound.
 */
interface AttendanceWeighting
{
    /**
     * How much this status contributes to the numerator, from 0.0 to 1.0.
     */
    public function attendanceCredit(string $status): float;

    /**
     * Whether a session with this status stays in the denominator.
     */
    public function countsTowardTotal(string $status): bool;
}
