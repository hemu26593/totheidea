<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Trackers\DayPlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The nightly carry-forward.
 *
 * "Unfinished tasks move to the next day automatically." Implemented as a
 * COPY FORWARD WITH A LINK, never a date mutation - see DayPlanService.
 *
 * SAFE TO RUN REPEATEDLY. The sweep only sees `planned` items and its first
 * act on each is to settle the original, so a second run in the same night, a
 * retry after a crash, or a manual invocation all produce the same result.
 * Long-running work belongs on a queue, not in a web request.
 */
class CarryForwardDayPlanJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $asOf = null) {}

    public function handle(DayPlanService $dayPlans): void
    {
        $dayPlans->carryForward($this->asOf);
    }
}
