<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | External scoped access
    |--------------------------------------------------------------------------
    |
    | The one unauthenticated write path in the system. A grant is a scoped,
    | expiring CAPABILITY: it never creates an account, a password, a role or a
    | session, and it never outlives its own expiry.
    |
    | Nothing here can widen a grant. These are limits on how a grant may be
    | exercised, not on what it permits.
    |
    */

    'redemption' => [

        /*
        | Rate limiting. Redemption is the only endpoint an unauthenticated
        | caller can reach, so it is also the only place a token could be
        | guessed at. A 256-bit token is not brute-forceable, but a limit costs
        | nothing and bounds any future weaker token.
        |
        | Keyed by caller identity (IP), not by token: keying by token would
        | let an attacker enumerate tokens freely as long as each was tried
        | once.
        */
        'max_attempts' => (int) env('ACCESS_REDEMPTION_MAX_ATTEMPTS', 10),

        'decay_seconds' => (int) env('ACCESS_REDEMPTION_DECAY_SECONDS', 60),

        /*
        | The single message returned for every refusal.
        |
        | An unknown token, an expired token, a revoked token, a token for
        | another customer and a token for another action all fail
        | IDENTICALLY. Any difference - text, code, or timing that a caller can
        | observe - turns the endpoint into an oracle that confirms which
        | tokens exist.
        */
        'denial_message' => 'This link is not valid.',
    ],

];
