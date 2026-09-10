<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery channels for the nine SOW reminder triggers (Step 3B).
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
