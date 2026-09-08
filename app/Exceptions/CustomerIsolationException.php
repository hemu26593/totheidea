<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A write attempted to associate records belonging to different customers.
 *
 * SOW section 6: "One participant can never see another participant's data. This
 * is built in, not a setting." Policies produce the 403 for HTTP callers;
 * this is the integrity guarantee for every caller, including console
 * commands and queued jobs.
 *
 * Reaching this exception means an identifier was trusted that should have
 * been verified, so it fails loudly rather than writing a mis-scoped row.
 */
class CustomerIsolationException extends RuntimeException
{
    public static function mismatch(string $what, string $expected, string $actual): self
    {
        return new self(
            "Refused: {$what} belongs to {$actual}, not to {$expected}."
        );
    }

    /**
     * A context assembled for one customer was used to generate against
     * another. The only way this can happen is a caller mixing the two, and
     * it is refused rather than written.
     */
    public static function contextCustomerMismatch(int $contextCustomerId, int $namedCustomerId): self
    {
        return new self(sprintf(
            'Refused: AI context was assembled for customer %d but the generation names customer %d.',
            $contextCustomerId,
            $namedCustomerId,
        ));
    }

    public static function unverifiableSubject(string $subjectType): self
    {
        return new self(
            "Refused: ownership of subject [{$subjectType}] cannot be verified, so a grant cannot be scoped to it."
        );
    }
}
