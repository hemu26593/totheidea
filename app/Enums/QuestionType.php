<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Question input types supported by the form engine (Step 3B).
 */
enum QuestionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case SelectOne = 'select_one';
    case SelectMany = 'select_many';
    case Scale = 'scale';

    /**
     * Types whose answers are recorded as question_options selections.
     */
    public function usesOptions(): bool
    {
        return in_array($this, [self::SelectOne, self::SelectMany], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
