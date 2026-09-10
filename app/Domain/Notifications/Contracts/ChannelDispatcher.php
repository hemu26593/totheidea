<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\DispatchResult;
use App\Models\NotificationDispatch;

/**
 * Hands a message to a delivery provider.
 *
 * The seam exists so the trigger system never has to change when a channel is
 * added. Triggers decide WHO and WHEN; this decides HOW, and nothing above it
 * knows the difference between email and anything else.
 *
 * NO PROVIDER IS WIRED IN THIS PHASE. WhatsApp and paid messaging are
 * explicitly out of scope, and the default binding refuses rather than
 * pretending to send - a dispatcher that reported success without sending
 * would make the dispatch log lie, which is the one thing this table exists
 * not to do.
 */
interface ChannelDispatcher
{
    public function send(NotificationDispatch $dispatch): DispatchResult;
}
