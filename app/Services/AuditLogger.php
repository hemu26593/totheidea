<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit entries (ADR-013).
 *
 * The single entry point for the audit trail. Future domain modules call this
 * rather than touching AuditLog directly, so redaction and actor resolution
 * stay in one place.
 */
class AuditLogger
{
    /**
     * Attribute names that must never reach the audit table, whatever the
     * caller passes. An audit trail is read by more people than the records it
     * describes, so it is the wrong place for secrets.
     *
     * @var array<int, string>
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        AuditAction $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            // Denormalised so the trail stays readable after a user is deleted.
            'actor_label' => $actor?->email,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $this->redact($oldValues),
            'new_values' => $this->redact($newValues),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->truncate($this->request->userAgent()),
        ]);
    }

    /**
     * Record only the attributes that actually changed, so the trail shows the
     * change rather than a full copy of the record on both sides.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logChanges(
        AuditAction $action,
        Model $auditable,
        array $before,
        array $after,
        ?User $actor = null,
    ): ?AuditLog {
        $changedKeys = array_keys(array_filter(
            $after,
            fn (mixed $value, string $key): bool => ! array_key_exists($key, $before) || $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changedKeys === []) {
            return null;
        }

        return $this->log(
            $action,
            $auditable,
            array_intersect_key($before, array_flip($changedKeys)),
            array_intersect_key($after, array_flip($changedKeys)),
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }

    private function truncate(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 512);
    }
}
