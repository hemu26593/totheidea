<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\HrPolicyAcknowledgementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One sign-off: this position, this policy, this name, this moment.
 *
 * IMMUTABLE. A sign-off is evidence, and evidence that can be edited afterwards
 * is not evidence. A change of mind, or a revised policy, is a NEW
 * acknowledgement against a NEW policy version - which is exactly what
 * supersession exists to make possible.
 *
 * The guards are on the model rather than in the policy because 'update' is not
 * among authorization.guarded_abilities: Gate::before would grant it to a Super
 * Admin and the policy would never be consulted.
 *
 * WHO SIGNED IS A POSITION. The participant's staff have no platform accounts,
 * so there is no user_id here and never will be; acknowledged_name is what they
 * typed.
 */
#[Fillable([])]
class HrPolicyAcknowledgement extends Model
{
    use HasActorTriple;

    /** @use HasFactory<HrPolicyAcknowledgementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'acknowledged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'An acknowledgement is immutable. A re-acknowledgement belongs to a new policy '
                .'version, never to an edit of this row.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'An acknowledgement cannot be deleted. It is the record that a named person '
                .'signed off on specific words.'
            );
        });
    }

    public function hrPolicy(): BelongsTo
    {
        return $this->belongsTo(HrPolicy::class);
    }

    /**
     * WHO acknowledged - a position in the participant's org chart, not a
     * platform user.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }
}
