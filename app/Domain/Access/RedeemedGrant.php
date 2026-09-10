<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\AccessGrant;
use Illuminate\Database\Eloquent\Model;

/**
 * The result of a successful redemption.
 *
 * NOT an identity and NOT a session. It is the grant plus its resolved
 * subject, valid for the single call that produced it. Nothing here can be
 * stored in a session, attached to a guard, or turned into a user: an external
 * caller must re-present the token for every subsequent action, and every one
 * of those re-checks expiry, revocation and scope from the database.
 *
 * The plaintext token is deliberately absent. It arrived, it was used, and it
 * does not travel any further into the application.
 */
final readonly class RedeemedGrant
{
    public function __construct(
        public AccessGrant $grant,
        public Model $subject,
    ) {}

    public function customerId(): int
    {
        return (int) $this->grant->customer_id;
    }

    public function enrollmentId(): int
    {
        return (int) $this->grant->enrollment_id;
    }
}
