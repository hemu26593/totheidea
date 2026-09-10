<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One position in the participant's own org chart.
 *
 * OWNED BY THE CUSTOMER. An org chart is continuous business data and outlives
 * a programme run, so it hangs off the business rather than the enrolment.
 *
 * THE PERSON IN A POSITION IS NOT A PLATFORM USER. holder_name is a string,
 * deliberately and permanently: the participant's staff have no accounts, no
 * credentials and no roles here. A relationship to User would create the
 * customer login the architecture forbids.
 *
 * ARCHIVE-ONLY. Acknowledgements RESTRICT against this table, so a position
 * that has signed something cannot be erased out from under the record.
 *
 * Mass-assignment note: nothing is fillable. PositionService sets the customer,
 * the parent and the actor triple, and it enforces I13 - a parent in the same
 * business, and no cycles.
 */
#[Fillable([])]
class Position extends Model
{
    use BelongsToCustomer;
    use HasActorTriple;

    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException(
                'A position is archived, never deleted. Acknowledgements point at it, and a '
                .'compliance record that cannot say who signed is not a record.'
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
     * The position this one reports to.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_position_id');
    }

    /**
     * The positions reporting to this one.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'parent_position_id')->orderBy('id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(HrPolicyAcknowledgement::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isRoot(): bool
    {
        return $this->parent_position_id === null;
    }
}
