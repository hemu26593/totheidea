<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\ChannelDispatcher;
use App\Exceptions\ChannelNotConfiguredException;
use App\Models\NotificationDispatch;

/**
 * The default dispatcher: it refuses, loudly.
 *
 * Every part of the notification system above this class is finished -
 * eligibility, scheduling, deduplication, queueing, retry and logging. What is
 * missing is a provider, and this makes that state of affairs explicit at the
 * one point where it matters, instead of hiding it behind a no-op that would
 * record sends that never happened.
 *
 * Binding a real dispatcher is the whole change needed to start delivering.
 */
class UnconfiguredChannelDispatcher implements ChannelDispatcher
{
    public function send(NotificationDispatch $dispatch): DispatchResult
    {
        throw ChannelNotConfiguredException::for($dispatch->channel);
    }
}
