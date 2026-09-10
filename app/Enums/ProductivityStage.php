<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five stages of the owner productivity model (Step 3A/3B).
 *
 * The sequence is canonical and is part of the confirmed definition, so
 * sequence() transcribes the client's own stage numbering. It is an ordinal
 * position, not a score, and nothing is computed from it.
 */
enum ProductivityStage: string
{
    case Planning = 'Planning';
    case Enabling = 'Enabling';
    case Execution = 'Execution';
    case Monitoring = 'Monitoring';
    case Controlling = 'Controlling';

    public function sequence(): int
    {
        return match ($this) {
            self::Planning => 1,
            self::Enabling => 2,
            self::Execution => 3,
            self::Monitoring => 4,
            self::Controlling => 5,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
