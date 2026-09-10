<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FormVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * An immutable revision of a template.
 *
 * This is the load-bearing record of the whole form engine: a submission
 * binds here, so this row and its children are the evidence of what was
 * actually asked. Once published, the only permitted change is archival.
 *
 * Enforced on the model rather than in a policy, because a policy cannot make
 * an invariant absolute - Gate::before short-circuits policies for a Super
 * Admin on unguarded abilities.
 *
 * Mass-assignment note: nothing about publication is fillable. Publishing
 * goes through FormPublishingService, which holds the one-published-version
 * invariant.
 */
#[Fillable(['scoring_scheme_version'])]
class FormVersion extends Model
{
    /** @use HasFactory<FormVersionFactory> */
    use HasFactory;

    /**
     * Attributes that may still change after publication.
     *
     * Archiving a published version is permitted; rewriting it is not.
     *
     * @var array<int, string>
     */
    private const MUTABLE_AFTER_PUBLICATION = ['status', 'archived_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (FormVersion $version): void {
            if ($version->getOriginal('status') === 'draft') {
                return;
            }

            $rewritten = array_diff(array_keys($version->getDirty()), self::MUTABLE_AFTER_PUBLICATION);

            if ($rewritten !== []) {
                throw new RuntimeException(sprintf(
                    'Form version %d is published and cannot be rewritten (%s). Create a new version instead.',
                    $version->getKey(),
                    implode(', ', $rewritten),
                ));
            }
        });

        static::deleting(function (FormVersion $version): never {
            throw new RuntimeException(
                'A form version is never deleted: submissions bind to it as evidence of what was asked.'
            );
        });
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FormSection::class)->orderBy('position');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
