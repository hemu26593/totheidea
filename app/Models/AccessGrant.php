<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCustomer;
use App\Enums\GrantAbility;
use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A scoped, expiring CAPABILITY - not an identity, and not an account.
 *
 * The plaintext token is NEVER stored. Only its SHA-256 hash lives here, so a
 * leaked table grants nothing. token_hash is hidden from serialisation for the
 * same reason a password hash is.
 *
 * This model does NOT carry the actor triple: it IS the external half of that
 * triple. A grant is always issued by an internal user (issued_by).
 *
 * Redemption is Phase 5. isValid() exists here because issuing needs to be
 * able to state what "valid" will mean, and because revocation and expiry are
 * queried before then.
 *
 * Mass-assignment note: nothing about validity is fillable. token_hash,
 * use_count, revocation and expiry move only through AccessGrantService.
 */
#[Fillable([])]
#[Hidden(['token_hash'])]
class AccessGrant extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<AccessGrantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ability' => GrantAbility::class,
            'single_use' => 'boolean',
            'max_uses' => 'integer',
            'use_count' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function customerContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * The specific form version / assignment instance / date this grant is
     * scoped to. Polymorphic, so there is no database foreign key; the
     * subject's membership of the customer and enrolment is asserted by
     * AccessGrantService when the grant is issued.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->use_count >= $this->max_uses;
    }

    /**
     * A grant is valid only if unexpired AND unrevoked AND has uses left.
     * Redemption in Phase 5 re-checks this at the moment of use.
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked() && ! $this->isExhausted();
    }
}
