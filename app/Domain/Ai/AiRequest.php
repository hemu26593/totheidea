<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What the domain asks a provider for.
 *
 * THE SEAM. Everything above this class talks about prompts, customers and
 * approvals; everything below it talks about HTTP and models. Nothing in
 * app/Domain/Ai outside Providers/ knows which vendor is answering, which is
 * what CLAUDE.md AI rule 7 requires and what makes swapping providers a
 * one-class change.
 *
 * There is no message history and no tool list here, deliberately. This is a
 * one-shot, structured request for one named purpose - not a conversation,
 * and not a surface a model can act through.
 */
final readonly class AiRequest
{
    /**
     * @param  string  $instructions  the rendered prompt template - system vocabulary, no customer text
     * @param  string  $context  the customer context, clearly delimited and treated as DATA
     * @param  array<string, mixed>|null  $outputSchema  the shape the answer must take, if structured
     * @param  string|null  $modelIdentifier  pinned by the prompt version for provenance; null uses the configured default
     */
    public function __construct(
        public string $instructions,
        public string $context,
        public ?array $outputSchema = null,
        public ?string $modelIdentifier = null,
        public ?int $maxTokens = null,
    ) {}

    public function expectsStructuredOutput(): bool
    {
        return $this->outputSchema !== null;
    }
}
