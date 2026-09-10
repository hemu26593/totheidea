<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A server-side authorization invariant was violated.
 *
 * These are last-line integrity guards inside UserService, not the primary
 * authorization check — UserPolicy rejects these cases first and produces a
 * 403. Reaching this exception means something bypassed the policy (a console
 * command, a race, or a caller that forgot to authorize), so it is deliberately
 * an exception rather than a silent no-op.
 */
class AuthorizationRuleException extends RuntimeException
{
    public static function lastSuperAdmin(string $operation): self
    {
        return new self("Refused to {$operation}: this is the last active Super Admin.");
    }

    public static function selfAction(string $operation): self
    {
        return new self("Refused to {$operation}: a user cannot perform this action on their own account.");
    }

    public static function insufficientRank(string $operation): self
    {
        return new self("Refused to {$operation}: the target outranks the actor.");
    }

    public static function unassignableRole(string $role): self
    {
        return new self("Refused to assign role [{$role}]: it ranks above the actor.");
    }
}
