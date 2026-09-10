<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Providers\AnthropicMessagesProvider;
use App\Domain\Ai\Providers\UnconfiguredAiProvider;
use App\Exceptions\AiGenerationFailedException;
use App\Exceptions\AiProviderNotConfiguredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The provider seam: what happens when nothing is configured, and what
 * happens when something is.
 *
 * The first half of this file is about a failure mode that is easy to get
 * wrong in a way nobody notices - an application that answers plausibly when
 * it has no model behind it.
 */
class AiProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function with_no_provider_configured_the_container_resolves_a_refusing_provider(): void
    {
        config(['ai.provider' => 'null']);

        $this->assertInstanceOf(UnconfiguredAiProvider::class, app(AiProvider::class));
    }

    #[Test]
    public function an_unconfigured_provider_refuses_rather_than_inventing_a_response(): void
    {
        config(['ai.provider' => 'null']);

        $this->expectException(AiProviderNotConfiguredException::class);

        app(AiProvider::class)->generate(new AiRequest('instructions', 'context'));
    }

    #[Test]
    public function an_unknown_provider_name_fails_clearly_instead_of_falling_back(): void
    {
        config(['ai.provider' => 'some-provider-nobody-wrote']);

        $this->expectException(AiProviderNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/Unknown AI provider/');

        app(AiProvider::class);
    }

    #[Test]
    public function the_anthropic_provider_refuses_when_the_credential_is_absent(): void
    {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => null]);

        Http::fake();

        $this->expectException(AiProviderNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/ANTHROPIC_API_KEY/');

        app(AiProvider::class)->generate(new AiRequest('instructions', 'context'));

        Http::assertNothingSent();
    }

    #[Test]
    public function no_api_key_is_hard_coded_anywhere_in_the_configuration(): void
    {
        // Every credential must arrive through env(). A literal in config/ai.php
        // would be a committed secret the moment the file is pushed.
        $source = file_get_contents(config_path('ai.php'));

        $this->assertStringContainsString("env('ANTHROPIC_API_KEY')", $source);
        $this->assertSame(
            1,
            preg_match_all('/ANTHROPIC_API_KEY/', $source),
            'The API key must be referenced only as an environment variable.',
        );
        $this->assertStringNotContainsString('sk-ant', $source);
    }

    #[Test]
    public function there_is_no_fake_driver_reachable_from_configuration(): void
    {
        // A 'fake' or 'echo' driver would be one environment variable away
        // from putting invented text in front of a client. Test doubles are
        // bound explicitly by tests instead.
        foreach (['fake', 'echo', 'stub', 'dummy'] as $driver) {
            config(['ai.provider' => $driver]);

            try {
                app(AiProvider::class);
                $this->fail("[{$driver}] resolved to a provider; no fabricating driver may be configurable.");
            } catch (AiProviderNotConfiguredException $e) {
                $this->assertStringContainsString('Unknown AI provider', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_anthropic_provider_sends_the_prompt_and_reads_the_first_text_block(): void
    {
        config([
            'ai.provider' => 'anthropic',
            'ai.anthropic.api_key' => 'test-key',
            'ai.anthropic.model' => 'claude-opus-5',
        ]);

        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            // A thinking block ahead of the text is exactly the case that
            // breaks a provider that reads content[0] blindly.
            'content' => [
                ['type' => 'thinking', 'thinking' => 'ignored'],
                ['type' => 'text', 'text' => '{"ok":true}'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $response = app(AnthropicMessagesProvider::class)
            ->generate(new AiRequest('the instructions', 'the context'));

        $this->assertSame('{"ok":true}', $response->text);
        $this->assertSame('claude-opus-5', $response->modelIdentifier);
        $this->assertSame(15, $response->tokensUsed);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/v1/messages')
                && $request->hasHeader('x-api-key', 'test-key')
                && $body['system'] === 'the instructions'
                && $body['messages'][0]['content'] === 'the context'
                // No tools are declared. The model can answer; it cannot act.
                && ! array_key_exists('tools', $body);
        });
    }

    #[Test]
    public function a_provider_error_becomes_a_generation_failure_without_leaking_the_request(): void
    {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'test-key']);

        Http::fake(['*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

        try {
            app(AnthropicMessagesProvider::class)->generate(new AiRequest('i', 'c'));
            $this->fail('A failed provider call must raise.');
        } catch (AiGenerationFailedException $e) {
            $this->assertStringContainsString('529', $e->getMessage());
            $this->assertStringContainsString('overloaded_error', $e->getMessage());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    #[Test]
    public function a_response_with_no_text_block_is_a_failure_not_an_empty_string(): void
    {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'test-key']);

        Http::fake(['*' => Http::response(['content' => []])]);

        $this->expectException(AiGenerationFailedException::class);

        app(AnthropicMessagesProvider::class)->generate(new AiRequest('i', 'c'));
    }
}
