<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssignmentInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * An assignment released to a batch.
 *
 * title and instructions are a SNAPSHOT taken at release. Reading them from
 * the template instead would silently rewrite what a running batch was asked
 * the moment the curriculum was edited.
 *
 * CLOSED, NEVER DELETED - submissions refer to it.
 *
 * Mass-assignment note: nothing is fillable. AssignmentReleaseService takes
 * the snapshot and audits release and closure.
 */
#[Fillable([])]
class AssignmentInstance extends Model
{
    /** @use HasFactory<AssignmentInstanceFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CLOSED = 'closed';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_RELEASED,
        self::STATUS_CLOSED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'due_at' => 'datetime',
            'requires_attachment' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'A released assignment is closed, never deleted. Submissions refer to it.'
            );
        });
    }

    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    public function assignmentTemplate(): BelongsTo
    {
        return $this->belongsTo(AssignmentTemplate::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
