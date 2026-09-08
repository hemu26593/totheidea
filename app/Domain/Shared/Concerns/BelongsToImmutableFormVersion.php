<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Structural immutability for everything inside a published form version.
 *
 * A submission binds to a version, so the version's structure is the record
 * of what was actually asked. If a published version's sections, questions or
 * options could still change, every historical submission would silently
 * change meaning with them - a January answer would be read against a March
 * question.
 *
 * This is enforced at the MODEL, not in a policy. Gate::before short-circuits
 * policies for a Super Admin on unguarded abilities, so a policy return value
 * cannot make an invariant absolute. Nothing bypasses a model event.
 */
trait BelongsToImmutableFormVersion
{
    public static function bootBelongsToImmutableFormVersion(): void
    {
        static::creating(function (Model $model): void {
            $model->guardFormVersionIsEditable('added to');
        });

        static::updating(function (Model $model): void {
            $model->guardFormVersionIsEditable('changed in');
        });

        static::deleting(function (Model $model): void {
            $model->guardFormVersionIsEditable('removed from');
        });
    }

    /**
     * The version whose publication state governs this record.
     */
    abstract public function owningFormVersion(): ?FormVersion;

    public function guardFormVersionIsEditable(string $operation): void
    {
        $version = $this->owningFormVersion();

        if ($version === null) {
            return;
        }

        if (! $version->isDraft()) {
            throw new RuntimeException(sprintf(
                '%s cannot be %s form version %d: it is %s. Publish a new version instead.',
                class_basename($this),
                $operation,
                $version->getKey(),
                $version->status,
            ));
        }
    }
}
