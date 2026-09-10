<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a provider returned, in the only terms the domain cares about.
 *
 * Text, the model that produced it, and what it cost. Provider-specific
 * envelopes - block arrays, stop reasons, vendor ids - are unwrapped in the
 * adapter and never travel further, so a change of provider cannot ripple
 * into the domain.
 *
 * $text is UNTRUSTED. It has been through no validation at this point; it is
 * a proposal, and SchemaValidator decides whether it is usable.
 */
final readonly class AiResponse
{
    public function __construct(
        public string $text,
        public ?string $modelIdentifier = null,
        public ?int $tokensUsed = null,
    ) {}
}
