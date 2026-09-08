<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Triggers;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TRIGGER 6 - Dashboard missed 3 days running.
 *
 * Recipient: the internal user running the batch. Timing: immediately.
 * Source: a gap in mmd_entries.
 *
 * BLOCKED ON BUILD ORDER. mmd_entries is table 30, built in Phase 6.
 *
 * The three-day window IS specified by the SOW, so it lives in config and is
 * not the open question here - what is missing is the data to measure it
 * against. Unlike trigger 5, this one becomes answerable the moment the table
 * exists: a gap of three consecutive days is a gap however often entries are
 * expected.
 */
class DashboardMissedThreeDaysTrigger implements NotificationTrigger
{
    public function key(): string
    {
        return 'dashboard_missed_three_days';
    }

    public function unresolvedDependency(): ?string
    {
        return Schema::hasTable('mmd_entries')
            ? null
            : 'mmd_entries does not exist yet (table 30, Phase 6).';
    }

    /**
     * @return iterable<int, NotificationCandidate>
     */
    public function candidates(CarbonImmutable $asOf): iterable
    {
        throw new RuntimeException(
            'Trigger [dashboard_missed_three_days] cannot be evaluated until mmd_entries exists '
            .'(table 30, Phase 6). Its eligibility query belongs with that table, not here - '
            .'writing it against a table that does not exist would be building Phase 6 blind.'
        );
    }
}
