<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an operational write came from (Step 3B actor triple).
 *
 * audit_logs.actor_id assumes an authenticated user. Under the hybrid entry
 * model a mutation may arrive from an external grant with no user at all, so
 * the source discriminator is what keeps "did staff enter this, or did the
 * owner?" answerable.
 */
enum ActorSource: string
{
    case InternalUser = 'internal_user';
    case ExternalGrant = 'external_grant';
    case System = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
