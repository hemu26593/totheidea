<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\Contracts\AiProvider;
use App\Exceptions\AiProviderNotConfiguredException;

/**
 * The default provider: it refuses, loudly.
 *
 * THE DEFAULT IS DELIBERATE. Everything above this class - prompt versioning,
 * context assembly, schema validation, drafting, approval - is finished and
 * tested. What is absent is a vendor account, and this makes that state of
 * affairs explicit at the one point where it matters.
 *
 * It is NOT a stub that returns sample output. Canned text would flow into a
 * draft form or a report narrative looking exactly like a real answer, and
 * the first person to notice would be a client reading it. Production code
 * does not fake AI responses; test doubles live in tests.
 *
 * Setting AI_PROVIDER and its credentials is the whole change needed to start
 * generating.
 */
class UnconfiguredAiProvider implements AiProvider
{
    public function generate(AiRequest $request): AiResponse
    {
        throw AiProviderNotConfiguredException::make();
    }

    public function name(): string
    {
        return 'unconfigured';
    }
}
