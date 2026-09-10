<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a line sits within a monthly fund plan (Step 3B).
 *
 * Section is one of three independent dimensions on a fund plan line, the
 * others being planning type and due classification.
 */
enum FundPlanSection: string
{
    case FundIn = 'fund_in';
    case FundOut = 'fund_out';
    case MarketingBudget = 'marketing_budget';
    case SalesClosing = 'sales_closing';
    case Production = 'production';

    /**
     * Due classification is meaningful only on Fund IN lines.
     */
    public function acceptsDueClassification(): bool
    {
        return $this === self::FundIn;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
