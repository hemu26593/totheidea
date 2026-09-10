<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CarryForwardDayPlanJob;
use Illuminate\Console\Command;

/**
 * Queues the nightly day-plan carry-forward.
 *
 * The command queues; the job does the work. Identifying and copying a day's
 * unfinished tasks across every participant is not something to hold a
 * scheduler tick open for.
 */
class CarryForwardDayPlans extends Command
{
    protected $signature = 'bmp:day-plans:carry-forward
                            {--as-of= : Treat this date as today (ISO-8601). For backfills and testing.}';

    protected $description = 'Carry unfinished day plan tasks forward to the next day';

    public function handle(): int
    {
        CarryForwardDayPlanJob::dispatch($this->option('as-of'));

        $this->info('Day plan carry-forward queued.');

        return self::SUCCESS;
    }
}
