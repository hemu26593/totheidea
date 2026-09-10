<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\NotificationChannel;
use RuntimeException;

/**
 * No delivery provider is wired for a channel.
 *
 * This is a real failure and is recorded as one. The alternative - quietly
 * marking the dispatch sent - would put a false record in the one table that
 * answers "did we actually contact this business?".
 */
class ChannelNotConfiguredException extends RuntimeException
{
    public static function for(NotificationChannel $channel): self
    {
        return new self(sprintf(
            'No delivery driver is configured for channel [%s]. Notification triggers, '
            .'scheduling, idempotency and logging are complete; provider integration is not '
            .'part of this phase.',
            $channel->value,
        ));
    }
}
