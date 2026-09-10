<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\Contracts\AiProvider;
use RuntimeException;

/**
 * A provider double, and it lives HERE - in tests/ - on purpose.
 *
 * Production code never fabricates an AI response. The unconfigured provider
 * refuses instead, so there is no driver name, environment variable or
 * misconfiguration that could make the running application answer with canned
 * text. A test that needs a scripted answer binds this class explicitly.
 *
 * It also RECORDS what it was asked, which is how the isolation and
 * untrusted-text tests assert on the bytes that would actually have left the
 * building.
 */
class RecordingAiProvider implements AiProvider
{
    /** @var array<int, AiRequest> */
    public array $requests = [];

    /** @var array<int, string|\Throwable> */
    private array $script;

    /**
     * @param  array<int, string|\Throwable>|string|\Throwable  $script
     */
    public function __construct($script = '{}')
    {
        $this->script = is_array($script) ? $script : [$script];
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->script) ?? throw new RuntimeException(
            'The provider double was called more times than it was scripted for.'
        );

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return new AiResponse(text: $next, modelIdentifier: 'test-model', tokensUsed: 42);
    }

    public function name(): string
    {
        return 'recording-double';
    }

    public function lastRequest(): AiRequest
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /**
     * Everything that would have been sent, as one string.
     */
    public function lastPayload(): string
    {
        $request = $this->lastRequest();

        return $request->instructions."\n".$request->context;
    }
}
