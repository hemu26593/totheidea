<?php

declare(strict_types=1);

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use App\Exceptions\AiGenerationFailedException;
use App\Exceptions\AiProviderNotConfiguredException;

/**
 * The transport boundary.
 *
 * ONE METHOD, ON PURPOSE. The domain sends instructions plus delimited
 * context and receives text. There is no method to continue a conversation,
 * none to call a tool, and none to run anything - a provider cannot be asked
 * to do more than answer, so no future adapter can quietly widen what AI is
 * allowed to do in this application.
 *
 * Implementations THROW rather than return an error object: a failed
 * generation must be recorded as failed, and a silent empty answer would be
 * indistinguishable from a model with nothing to say.
 */
interface AiProvider
{
    /**
     * @throws AiProviderNotConfiguredException when no provider or credential is configured
     * @throws AiGenerationFailedException when the provider does not return usable text
     */
    public function generate(AiRequest $request): AiResponse;

    /**
     * A short identifier recorded on the generation for provenance.
     */
    public function name(): string;
}
