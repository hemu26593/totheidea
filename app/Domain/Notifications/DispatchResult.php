<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What a provider said about one send.
 */
final readonly class DispatchResult
{
    public function __construct(
        public string $providerMessageId,
    ) {}
}
