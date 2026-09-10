<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Finds the notification work that is due and queues it.
 *
 * SAFE TO RUN REPEATEDLY. Running it twice in a minute queues the same sends
 * once, because the dedupe key - not this command - is what decides whether a
 * notification has already been dealt with.
 *
 * The command never sends anything. It identifies work; the queue delivers it.
 */
class SweepNotifications extends Command
{
    protected $signature = 'bmp:notifications:sweep
                            {--as-of= : Evaluate as though it were this moment (ISO-8601). For backfills and testing.}';

    protected $description = 'Queue every notification that qualifies right now';

    public function handle(NotificationScheduler $scheduler): int
    {
        $asOf = $this->option('as-of') !== null
            ? CarbonImmutable::parse((string) $this->option('as-of'))
            : CarbonImmutable::now();

        $result = $scheduler->sweep($asOf);

        $this->info(sprintf(
            'Swept at %s: %d queued, %d already handled, %d suppressed.',
            $asOf->toIso8601String(),
            $result->totalCreated(),
            $result->totalDuplicates(),
            $result->totalSuppressed(),
        ));

        foreach ($result->deferred as $trigger => $reason) {
            // Reported, never silent. A deferred trigger looks exactly like a
            // trigger with no qualifying records unless it is said out loud.
            $this->warn("Deferred [{$trigger}]: {$reason}");
        }

        return self::SUCCESS;
    }
}
