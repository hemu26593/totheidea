<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\AccessGrant;
use App\Models\CustomerContact;
use Illuminate\Support\Carbon;

/**
 * A form link that has just been issued, as the operator who issued it needs
 * to see it.
 *
 * THE URL IN HERE IS THE ONLY COPY THERE WILL EVER BE. The database holds the
 * token's SHA-256 hash and nothing else, so this object is the single moment
 * the plaintext exists outside the recipient's inbox. It is built, shown once
 * to the person who pressed the button, and discarded with the response - it
 * is never stored, audited, logged, queued or put into a redirect of its own.
 *
 * Returning it at all is a deliberate reversal of the original "never returned"
 * rule, recorded as ADR-018: the client has no mail transport configured, so a
 * link nobody can copy is a link nobody can send.
 */
final readonly class IssuedLink
{
    public function __construct(
        public AccessGrant $grant,
        public string $url,
        public ?CustomerContact $contact,
        /** Whether the message actually went out, or there is no transport. */
        public bool $delivered,
    ) {}

    public function expiresAt(): ?Carbon
    {
        return $this->grant->expires_at;
    }
}
