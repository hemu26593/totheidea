<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * MSD — the three business functions (Step 3A/3B confirmed vocabulary).
 */
enum BusinessFunction: string
{
    case Marketing = 'Marketing';
    case Sales = 'Sales';
    case Delivery = 'Delivery';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
