<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\HrPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One HR policy belonging to the participant's business.
 *
 * SUPERSEDED, NEVER EDITED IN PLACE. Once published, the words are frozen. The
 * guard below is the absolute form of that rule, and it is on the model rather
 * than only in HrPolicyPolicy for a specific reason: Gate::before grants a
 * Super Admin every ability outside authorization.guarded_abilities, and
 * 'update' is not one of them - so a policy returning false would be bypassed
 * for exactly the account most able to do damage.
 *
 * What may still move after publication is the record of what BECAME of the
 * policy: its status, its supersession link, its archived_at. What may not is
 * what it SAID. Every acknowledgement is a person attesting to specific words,
 * and rewriting them would make those attestations false retroactively.
 */
#[Fillable([])]
class HrPolicy extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<HrPolicyFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_SUPERSEDED,
    ];

    /**
     * The columns that carry what the policy SAID. Frozen from publication
     * onwards.
     *
     * @var array<int, string>
     */
    public const CONTENT_COLUMNS = ['title', 'body', 'version_label'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $policy): void {
            // A draft is still being written; a published policy is not.
            if ($policy->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }

            foreach (self::CONTENT_COLUMNS as $column) {
                if ($policy->isDirty($column)) {
                    throw new RuntimeException(sprintf(
                        'HR policy %s is [%s]: its %s cannot be changed. A revision is a new '
                        .'policy that supersedes this one, so acknowledgements stay bound to the '
                        .'text that was actually acknowledged.',
                        $policy->getKey(),
                        $policy->getOriginal('status'),
                        $column,
                    ));
                }
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'An HR policy is archived or superseded, never deleted. Deleting one would take '
                .'its acknowledgements with it.'
            );
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    /**
     * The policy that replaced this one.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * The policy this one replaced.
     */
    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(HrPolicyAcknowledgement::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    /**
     * A policy people can be asked to sign off on.
     */
    public function isAcknowledgeable(): bool
    {
        return $this->isPublished() && $this->archived_at === null;
    }
}
