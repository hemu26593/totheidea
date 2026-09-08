<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two decisions a reviewer can record (Step 3B, table 42).
 *
 * There is no third value. "Needs changes" is a rejection with a remark
 * followed by a NEW generation, which keeps one decision per generation and
 * leaves the rejected attempt intact as provenance.
 */
enum AiApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
