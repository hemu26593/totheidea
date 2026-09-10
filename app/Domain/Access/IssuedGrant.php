<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\AccessGrant;

/**
 * The result of issuing a grant.
 *
 * The plaintext token exists ONLY here, in memory, on the way to the link that
 * is emailed. It is never persisted - the database holds its SHA-256 hash and
 * nothing else - and it is never logged or audited.
 */
final readonly class IssuedGrant
{
    public function __construct(
        public AccessGrant $grant,
        public string $plaintextToken,
    ) {}
}
