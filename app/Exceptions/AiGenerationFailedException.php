<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider call did not produce usable output.
 *
 * Carries a short, safe reason for storage on the generation row. The message
 * is written for the person reviewing the approval queue, and never contains
 * credentials or another customer's data.
 */
class AiGenerationFailedException extends RuntimeException
{
    public static function transport(string $reason): self
    {
        return new self("The AI provider did not return a usable response: {$reason}");
    }

    public static function emptyResponse(): self
    {
        return new self('The AI provider returned no text content.');
    }
}
