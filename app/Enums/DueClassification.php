<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ageing of a Fund IN receivable (Step 3A/3B confirmed vocabulary).
 *
 * The vocabulary is complete at three values. There is no fourth due
 * classification. It is modelled as a value on a fund plan line rather than
 * one column per class, so "all Long Due across months" is a query rather
 * than a UNION.
 */
enum DueClassification: string
{
    case CurrentDue = 'CD';
    case LongDue = 'LD';
    case LongLongDue = 'LLD';

    public function label(): string
    {
        return match ($this) {
            self::CurrentDue => 'Current Due',
            self::LongDue => 'Long Due',
            self::LongLongDue => 'Long Long Due',
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
