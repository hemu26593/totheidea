<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Enums\ActorSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The actor triple carried by every operational write (Step 3B, invariant I10).
 *
 * Under the hybrid entry model a row may be written by internal staff, by an
 * external scoped grant, or by the system. Recording which is what makes
 * "did staff enter this, or did the owner?" answerable.
 *
 * The invariant is application-enforced because conditional NOT NULL is not
 * portably expressible across SQLite and MySQL:
 *
 *   source = internal_user   -> created_by set,       access_grant_id null
 *   source = external_grant  -> access_grant_id set,  created_by null
 *   source = system          -> both null
 *
 * Applied to models from Phase 1 onward. The accessGrant() relationship is
 * deliberately absent until the access_grants table exists.
 */
trait HasActorTriple
{
    public static function bootHasActorTriple(): void
    {
        static::saving(static function (Model $model): void {
            self::guardActorTriple(
                $model->getAttribute('source'),
                $model->getAttribute('created_by'),
                $model->getAttribute('access_grant_id'),
            );
        });
    }

    /**
     * The rule, as a pure function, so it is testable without a table.
     */
    public static function actorTripleIsCoherent(
        ActorSource|string|null $source,
        int|string|null $createdBy,
        int|string|null $accessGrantId,
    ): bool {
        $source = $source instanceof ActorSource ? $source : ActorSource::tryFrom((string) $source);

        return match ($source) {
            ActorSource::InternalUser => $createdBy !== null && $accessGrantId === null,
            ActorSource::ExternalGrant => $accessGrantId !== null && $createdBy === null,
            ActorSource::System => $createdBy === null && $accessGrantId === null,
            null => false,
        };
    }

    public static function guardActorTriple(
        ActorSource|string|null $source,
        int|string|null $createdBy,
        int|string|null $accessGrantId,
    ): void {
        if (! self::actorTripleIsCoherent($source, $createdBy, $accessGrantId)) {
            throw new RuntimeException(
                'Incoherent actor triple: exactly one of created_by / access_grant_id must be set, '
                .'and neither when the source is system.'
            );
        }
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function wasWrittenExternally(): bool
    {
        return $this->getAttribute('source') === ActorSource::ExternalGrant;
    }
}
