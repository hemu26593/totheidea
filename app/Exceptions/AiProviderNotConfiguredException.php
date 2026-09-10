<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * No AI provider is wired.
 *
 * This is a real failure and is reported as one. The alternative - returning
 * canned text so the pipeline "works" - would put fabricated output into a
 * draft form or a report narrative with nothing to distinguish it from a real
 * answer. A stub that lies is worse than an outage that says so.
 */
class AiProviderNotConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No AI provider is configured. Set AI_PROVIDER and the credentials for that provider '
            .'in the environment. Generation, validation, drafting and approval are complete; '
            .'nothing about them fabricates a response when the provider is absent.'
        );
    }

    public static function missingCredential(string $provider, string $key): self
    {
        return new self(sprintf(
            'The [%s] AI provider is selected but [%s] is not set in the environment. '
            .'Credentials are never committed and never hard-coded.',
            $provider,
            $key,
        ));
    }

    public static function unknownProvider(string $provider): self
    {
        return new self(sprintf(
            'Unknown AI provider [%s]. Configure one of the drivers registered in AppServiceProvider, '
            .'or leave AI_PROVIDER unset to refuse generation outright.',
            $provider,
        ));
    }
}
