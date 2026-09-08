<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\Contracts\ChannelDispatcher;
use App\Models\NotificationDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Performs one send.
 *
 * IDEMPOTENT BY RE-READING STATE. The job carries an id, not a model: a
 * serialised model would carry a snapshot of a row that may have been settled
 * by an earlier attempt, and the job would then send a message that had
 * already gone out.
 *
 * A dispatch that is no longer pending is left alone, so a duplicated job, a
 * replayed queue message and a retry after a worker crash all converge on one
 * send.
 *
 * Failure is recorded on the row, never swallowed: the attempt count, the last
 * attempt time and the provider's error all live on the dispatch, which is the
 * record the audit trail deliberately does not duplicate.
 */
class DispatchNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $dispatchId) {}

    public function tries(): int
    {
        return (int) config('notifications.max_attempts', 3);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return (array) config('notifications.backoff', [60, 300, 900]);
    }

    public function handle(ChannelDispatcher $dispatcher): void
    {
        $dispatch = NotificationDispatch::query()->find($this->dispatchId);

        if ($dispatch === null || ! $dispatch->isPending()) {
            return;
        }

        $dispatch->forceFill([
            'attempts' => (int) $dispatch->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $result = $dispatcher->send($dispatch);
        } catch (Throwable $e) {
            $this->recordFailure($dispatch, $e);

            // Rethrown so the queue applies its own retry and backoff. The row
            // already carries the attempt, so nothing is lost if the worker
            // dies before the queue records anything.
            throw $e;
        }

        $dispatch->forceFill([
            'status' => NotificationDispatch::STATUS_SENT,
            'sent_at' => now(),
            'provider_message_id' => $result->providerMessageId,
            'error' => null,
        ])->save();
    }

    /**
     * Called by the queue when every attempt has been used.
     */
    public function failed(?Throwable $e): void
    {
        $dispatch = NotificationDispatch::query()->find($this->dispatchId);

        if ($dispatch === null || ! $dispatch->isPending()) {
            return;
        }

        $dispatch->forceFill([
            'status' => NotificationDispatch::STATUS_FAILED,
            'error' => $e?->getMessage(),
        ])->save();
    }

    private function recordFailure(NotificationDispatch $dispatch, Throwable $e): void
    {
        $exhausted = (int) $dispatch->attempts >= $this->tries();

        $dispatch->forceFill([
            'status' => $exhausted
                ? NotificationDispatch::STATUS_FAILED
                : NotificationDispatch::STATUS_PENDING,
            'error' => $e->getMessage(),
        ])->save();
    }
}
