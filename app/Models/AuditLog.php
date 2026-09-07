<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * An append-only audit entry (ADR-013).
 *
 * Entries are written through the AuditLogger service, never constructed
 * directly by feature code. The model refuses updates and deletes: an audit
 * trail that can be edited is not evidence.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_label',
        // The source discriminator. Under the hybrid entry model a mutation
        // may arrive from an external grant with no user at all, so actor_id
        // alone cannot answer "did staff enter this, or did the owner?".
        'source',
        'access_grant_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'source' => ActorSource::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit log entries are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The scoped grant a write arrived through, when the source was external.
     */
    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }
}
