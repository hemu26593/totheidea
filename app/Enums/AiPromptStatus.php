<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a prompt version (Step 3B, table 40).
 *
 * Deliberately the same three words the form engine uses for a form version,
 * because it is the same idea: draft while it is being written, published
 * while it is the one in use, archived once superseded. A published prompt is
 * immutable for exactly the reason a published form version is - things
 * already point at it.
 */
enum AiPromptStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
