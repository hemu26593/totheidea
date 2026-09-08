<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Commentary on a subject, including the consultant's private notes.
 *
 * SOW module 14 requires private notes participants cannot see, and SOW
 * section 6 requires that isolation be "built in, not a setting". Two design
 * choices follow:
 *
 *   1. is_internal defaults to TRUE, so forgetting the flag over-restricts
 *      rather than leaks.
 *   2. There is NO actor triple. A note is authored by a member of staff, and
 *      author_id is NOT NULL - so an external grant structurally cannot
 *      create one. That is deliberate, and asserted by a test.
 *
 * Mass-assignment note: is_internal and author_id are excluded. Visibility is
 * decided by NoteService, never by request input.
 */
#[Fillable(['body'])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Always a member of internal staff. A customer is not a user and can
     * never appear here.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Notes that may be surfaced on an externally-authorised read path.
     *
     * This is the visibility gate, expressed once as a query scope so that a
     * future external route cannot expose an internal note by omitting a
     * condition.
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
