<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TRIGGER 5 - Daily dashboard not filled.
 *
 * Recipient: participant. Timing: the same evening.
 * Source: absence of an mmd_entries row.
 *
 * BLOCKED, ON TWO SEPARATE THINGS.
 *
 *   1. BUILD ORDER. mmd_entries is table 30, built in Phase 6. There is no
 *      data to ask about yet, and creating the table here would be building a
 *      later phase.
 *
 *   2. [L6] - the dashboard entry CADENCE is an open client decision. "Not
 *      filled today" presupposes that an entry is expected every day, which is
 *      exactly what L6 has not settled. A weekly dashboard would make a daily
 *      reminder wrong rather than merely early.
 *
 * The trigger is registered and its wiring is complete, so it starts working
 * when both are resolved. It does not guess in the meantime, and the scheduler
 * reports it as deferred rather than passing over it in silence.
 */
class DailyDashboardMissingTrigger implements NotificationTrigger
{
    public function key(): string
    {
        return 'daily_dashboard_missing';
    }

    public function unresolvedDependency(): ?string
    {
        if (! Schema::hasTable('mmd_entries')) {
            return 'mmd_entries does not exist yet (table 30, Phase 6), and [L6] - the dashboard '
                .'entry cadence - is an open client decision.';
        }

        // The table arriving does not settle the cadence. L6 must be answered
        // before "missing" means anything.
        return '[L6] the dashboard entry cadence is an open client decision; "not filled today" '
            .'presupposes a daily expectation that has not been confirmed.';
    }

    /**
     * @return iterable<int, NotificationCandidate>
     */
    public function candidates(CarbonImmutable $asOf): iterable
    {
        throw new RuntimeException(
            'Trigger [daily_dashboard_missing] has an unresolved dependency and must not be '
            .'evaluated. '.$this->unresolvedDependency()
        );
    }
}
