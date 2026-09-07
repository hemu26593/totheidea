<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Behaviour dimensions from the business productivity model (Step 3A/3B).
 */
enum BehaviourDimension: string
{
    case DisciplineThinking = 'DT';
    case ClearCommunication = 'CC';
    case PurposefulAction = 'PA';

    public function label(): string
    {
        return match ($this) {
            self::DisciplineThinking => 'Discipline Thinking',
            self::ClearCommunication => 'Clear Communication',
            self::PurposefulAction => 'Purposeful Action',
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
