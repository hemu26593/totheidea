<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an AI invocation is for (Step 3B, table 41).
 *
 * A CLOSED SET, and that is the point. There is no "chat", no "ask anything",
 * no free-form purpose - every invocation is one of a small number of named
 * jobs whose output shape is known in advance and validated against a schema.
 * Adding a purpose is a deliberate act with a prompt version behind it.
 */
enum AiPurpose: string
{
    /** Proposes form structure, which enters the ordinary form engine as a draft. */
    case FormDraft = 'form_draft';

    /** Prose attached alongside a report's PHP-computed figures, never in place of one. */
    case Narrative = 'narrative';

    /** A prose summary of material the reader already has access to. */
    case Summary = 'summary';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
