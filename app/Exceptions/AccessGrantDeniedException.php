<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A refusal of external scoped access.
 *
 * EVERY refusal is this exception with the SAME message. An unknown token, an
 * expired one, a revoked one, an exhausted one, one for another customer and
 * one for another action are indistinguishable to the caller. Anything else
 * turns the redemption endpoint into an oracle: try a token, read the
 * difference, learn whether it exists.
 *
 * The internal reason is carried separately in $reason. It is written to the
 * audit trail, which is read by staff, and is NEVER returned to the caller or
 * put in the message.
 */
class AccessGrantDeniedException extends RuntimeException
{
    public const REASON_UNKNOWN_TOKEN = 'unknown_token';

    public const REASON_EXPIRED = 'expired';

    public const REASON_REVOKED = 'revoked';

    public const REASON_EXHAUSTED = 'exhausted';

    public const REASON_WRONG_ABILITY = 'wrong_ability';

    public const REASON_WRONG_SUBJECT = 'wrong_subject';

    public const REASON_SUBJECT_MISSING = 'subject_missing';

    public const REASON_OUT_OF_SCOPE = 'out_of_scope';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_RACE_LOST = 'race_lost';

    private function __construct(public readonly string $reason)
    {
        parent::__construct((string) config('access.redemption.denial_message', 'This link is not valid.'));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
