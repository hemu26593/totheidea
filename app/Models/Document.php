<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A file attached to a subject.
 *
 * Ownership is resolved through the subject, not through a column here, so
 * every read must go through a path that resolves it. is_internal defaults to
 * true for exactly that reason: a document whose visibility was never decided
 * must not be visible.
 *
 * Archive-only. The stored file is not removed when a row is archived,
 * because a delivered document must stay retrievable.
 *
 * Mass-assignment note: is_internal and the actor triple are excluded.
 * Visibility and provenance are set by DocumentService.
 */
#[Fillable(['disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'checksum_sha256'])]
class Document extends Model
{
    use HasActorTriple;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'size_bytes' => 'integer',
            'is_internal' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Documents that may be surfaced on an externally-authorised read path.
     *
     * Internal documents are excluded here rather than at the call site, so a
     * future external route cannot expose one by forgetting a condition.
     */
    public function scopeExternallyVisible(Builder $query): Builder
    {
        return $query->where('is_internal', false)->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
