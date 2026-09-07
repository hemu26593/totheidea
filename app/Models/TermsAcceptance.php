<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use Database\Factories\TermsAcceptanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The record that terms were accepted on screen, with name, date and time.
 *
 * IMMUTABLE. A legal acceptance is evidence, and evidence that can be edited
 * afterwards is not evidence. A correction is a new row against a new terms
 * version, which UNIQUE (enrollment_id, terms_version) permits.
 *
 * Carries the actor triple: this is the clearest case of an externally-sourced
 * write, since the acceptor reaches it through a scoped grant rather than an
 * account.
 */
#[Fillable([])]
class TermsAcceptance extends Model
{
    use HasActorTriple;

    /** @use HasFactory<TermsAcceptanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ActorSource::class,
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'A terms acceptance is immutable. Record a new acceptance against a new terms version.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException('A terms acceptance cannot be deleted.');
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }
}
