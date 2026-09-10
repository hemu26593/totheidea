<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Models\NotificationDispatch;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Runs every trigger and queues what qualifies.
 *
 * SAFE TO RUN AS OFTEN AS YOU LIKE. That is the whole design goal: the
 * scheduler identifies work, the dedupe key decides whether it has already
 * been done, and the queue performs the send. Running twice in a minute
 * produces the same dispatches as running once.
 *
 * The scheduler never sends anything itself. Identifying eligibility and
 * talking to a provider have completely different failure modes, and mixing
 * them would mean a provider timeout could stop the sweep finding the rest of
 * the day's work.
 */
class NotificationScheduler
{
    public function __construct(
        private readonly NotificationDispatchService $dispatches,
    ) {}

    public function sweep(?CarbonImmutable $asOf = null): SweepResult
    {
        $asOf ??= CarbonImmutable::now();
        $result = new SweepResult;

        foreach ($this->triggers() as $trigger) {
            $blocked = $trigger->unresolvedDependency();

            if ($blocked !== null) {
                // Reported, not skipped silently. A trigger waiting on a later
                // phase or an open client decision is a known state of the
                // system, not an absence.
                $result->recordDeferred($trigger->key(), $blocked);

                continue;
            }

            foreach ($trigger->candidates($asOf) as $candidate) {
                $dispatch = $this->dispatches->queue($candidate);

                if ($dispatch === null) {
                    $result->recordDuplicate($trigger->key());

                    continue;
                }

                if ($dispatch->status === NotificationDispatch::STATUS_SUPPRESSED) {
                    $result->recordSuppressed($trigger->key());

                    continue;
                }

                $this->dispatches->enqueue($dispatch);
                $result->recordCreated($trigger->key());
            }
        }

        return $result;
    }

    /**
     * The nine triggers, as configured code.
     *
     * @return array<int, NotificationTrigger>
     */
    public function triggers(): array
    {
        return array_map(function (string $class): NotificationTrigger {
            $trigger = app($class);

            if (! $trigger instanceof NotificationTrigger) {
                throw new InvalidArgumentException(
                    "[{$class}] is registered as a notification trigger but does not implement the contract."
                );
            }

            return $trigger;
        }, (array) config('notifications.triggers', []));
    }
}
