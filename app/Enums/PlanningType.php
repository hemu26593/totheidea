<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a fund plan line was planned (Step 3A/3B confirmed vocabulary).
 */
enum PlanningType: string
{
    case BasicFinancePlanning = 'BFP';
    case ForcastBasePlanning = 'FBP';
    case ExternalBasePlanning = 'EBP';
    case StrategicManagement = 'SM';

    public function label(): string
    {
        return match ($this) {
            self::BasicFinancePlanning => 'Basic Finance Planning',
            self::ForcastBasePlanning => 'For-cast Base Planning',
            self::ExternalBasePlanning => 'External Base Planning',
            self::StrategicManagement => 'Strategic Management',
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
