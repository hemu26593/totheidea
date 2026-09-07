<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The MMD evaluation categories (Step 3A/3B confirmed vocabulary).
 *
 * These are CATEGORICAL. No numeric mapping is defined for them, and none is
 * to be invented: the client confirmed the four values as select options with
 * no scoring semantics.
 *
 * Two consequences follow, and both are enforced by tests:
 *
 *   1. question_options rows carrying these labels leave score_value NULL.
 *      Populating it would invent the Average = 1 ... Best = 4 mapping the
 *      brief explicitly forbids.
 *   2. submission_scores never holds one of these labels. That table is
 *      numeric; copying a category into it would create a second source of
 *      truth and invite exactly that mapping.
 *
 * This enum therefore exposes no ordinal, weight, score or comparison.
 */
enum ScoringCategory: string
{
    case Average = 'Average';
    case Good = 'Good';
    case Better = 'Better';
    case Best = 'Best';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
