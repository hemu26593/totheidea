<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\Contracts\AiProvider;
use App\Exceptions\AiGenerationFailedException;
use App\Exceptions\AiProviderNotConfiguredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The one place in this codebase that knows a vendor exists.
 *
 * WHY NO SDK. The Messages API call this adapter makes is a single JSON POST
 * with three headers. Laravel's HTTP client already handles the request,
 * timeouts, retries and testability, so an SDK would add a dependency - and
 * its transitive tree - to save nothing structural. CLAUDE.md rule 12 asks
 * that each dependency be justified rather than assumed, and this one cannot
 * be. The abstraction above (AiProvider) is what actually protects the
 * codebase from vendor coupling, and it is unaffected either way: swapping
 * this class for an SDK-backed one is a one-line binding change.
 *
 * CREDENTIALS COME FROM THE ENVIRONMENT AND ARE NEVER LOGGED. The key is read
 * from configuration at call time; an absent key is a clear refusal, not a
 * request that fails obscurely at the far end.
 *
 * IT SENDS, IT READS TEXT BACK, AND THAT IS ALL. No tools are declared, so
 * the model has no mechanism to act - it can only answer. Whatever comes back
 * is text bound for SchemaValidator, and is never executed, evaluated or
 * interpolated into a query.
 */
class AnthropicMessagesProvider implements AiProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function generate(AiRequest $request): AiResponse
    {
        $apiKey = (string) config('ai.anthropic.api_key', '');

        if (trim($apiKey) === '') {
            throw AiProviderNotConfiguredException::missingCredential('anthropic', 'ANTHROPIC_API_KEY');
        }

        $model = $request->modelIdentifier ?? (string) config('ai.anthropic.model');

        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => (string) config('ai.anthropic.version'),
                    'content-type' => 'application/json',
                ])
                ->timeout((int) config('ai.anthropic.timeout', 120))
                ->post(rtrim((string) config('ai.anthropic.base_url'), '/').'/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $request->maxTokens ?? (int) config('ai.anthropic.max_tokens', 4096),
                    'system' => $request->instructions,
                    'messages' => [
                        ['role' => 'user', 'content' => $request->context],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw AiGenerationFailedException::transport('the provider could not be reached');
        } catch (Throwable $e) {
            // Deliberately not the raw message: an exception from an HTTP
            // client can carry the request, and the request carries the key.
            throw AiGenerationFailedException::transport('the request to the provider could not be completed');
        }

        if ($response->failed()) {
            throw AiGenerationFailedException::transport($this->safeError($response));
        }

        return new AiResponse(
            text: $this->firstTextBlock($response->json('content')),
            modelIdentifier: $response->json('model') ?? $model,
            tokensUsed: $this->totalTokens($response->json('usage')),
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    /**
     * The response body is an array of typed content blocks. Reading
     * content[0]->text blindly breaks the moment a non-text block leads, so
     * the first TEXT block is selected explicitly.
     *
     * @param  mixed  $content
     */
    private function firstTextBlock($content): string
    {
        if (! is_array($content)) {
            throw AiGenerationFailedException::emptyResponse();
        }

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                return (string) $block['text'];
            }
        }

        throw AiGenerationFailedException::emptyResponse();
    }

    /**
     * @param  mixed  $usage
     */
    private function totalTokens($usage): ?int
    {
        if (! is_array($usage)) {
            return null;
        }

        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;

        if ($input === null && $output === null) {
            return null;
        }

        return (int) $input + (int) $output;
    }

    /**
     * A short, safe description of a provider error.
     *
     * The status and the vendor's own error type are useful for diagnosis;
     * the raw body is not repeated, because it is stored on the generation
     * row and read by people who should not be shown request internals.
     */
    private function safeError(Response $response): string
    {
        $type = $response->json('error.type');

        return $type === null
            ? "HTTP {$response->status()}"
            : "HTTP {$response->status()} ({$type})";
    }
}
