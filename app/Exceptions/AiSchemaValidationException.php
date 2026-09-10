<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Model output did not conform to the prompt version's declared schema.
 *
 * THE FAILURE IS THE FEATURE. Output that does not validate is never
 * persisted as validated_output and never reaches the form engine; the
 * generation is recorded as failed with the reason, and a human sees why.
 * Treating a model's response as a PROPOSAL that must pass validation - not
 * as an instruction - is the whole point of the schema.
 */
class AiSchemaValidationException extends RuntimeException
{
    /** @var array<int, string> */
    public array $violations = [];

    /**
     * @param  array<int, string>  $violations
     */
    public static function withViolations(array $violations): self
    {
        $exception = new self(
            'AI output did not conform to the declared schema: '.implode('; ', $violations)
        );

        $exception->violations = $violations;

        return $exception;
    }

    public static function notJson(string $reason): self
    {
        $exception = new self("AI output was not valid JSON: {$reason}");
        $exception->violations = [$reason];

        return $exception;
    }
}
