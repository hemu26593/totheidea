<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\NotificationCandidate;
use Carbon\CarbonImmutable;

/**
 * One of the nine SOW section 7 triggers.
 *
 * The triggers are CODE, not rows: none is invented, none is user-configurable
 * in v1, and each one's eligibility rule is a query over data that already
 * exists. A trigger that cannot yet answer "who qualifies?" says so through
 * unresolvedDependency() rather than guessing.
 */
interface NotificationTrigger
{
    /**
     * Stable identifier. Part of every dedupe key, so it must never change
     * once dispatches exist - a renamed trigger would re-send its whole
     * history.
     */
    public function key(): string;

    /**
     * Why this trigger cannot run yet, or null when it can.
     *
     * Two things can block a trigger, and the distinction is deliberate:
     *
     *   - a BUILD-ORDER dependency (a table from a later phase), or
     *   - an UNRESOLVED CLIENT DECISION.
     *
     * Neither is a reason to invent an eligibility rule. The scheduler skips
     * such a trigger and reports it, so a deferred trigger is visible rather
     * than quietly absent.
     */
    public function unresolvedDependency(): ?string;

    /**
     * Everyone who qualifies at this moment.
     *
     * Called only when unresolvedDependency() is null.
     *
     * @return iterable<int, NotificationCandidate>
     */
    public function candidates(CarbonImmutable $asOf): iterable;
}
