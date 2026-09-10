<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An internal staff account.
 *
 * Customers are NOT users and never authenticate (ADR-007). Nothing in this
 * model should ever be reused to represent a customer.
 *
 * Mass-assignment note: is_active, last_login_at and last_login_ip are
 * deliberately excluded from $fillable. Activation state changes only through
 * UserService, which enforces the safeguards and writes the audit entry.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The user's role. V1 assigns exactly one role per user (ADR-008); if the
     * data ever holds more, the highest-ranked one governs.
     */
    public function role(): ?UserRole
    {
        return $this->roles
            ->map(fn ($role): ?UserRole => UserRole::tryFrom($role->name))
            ->filter()
            ->sortByDesc(fn (UserRole $role): int => $role->rank())
            ->first();
    }

    /**
     * Privilege rank, or 0 when the user holds no recognised role.
     */
    public function rank(): int
    {
        return $this->role()?->rank() ?? 0;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === UserRole::SuperAdmin;
    }

    /**
     * Whether this user outranks or matches the given user.
     *
     * An actor may only act on users they rank at or above (ADR-012), which is
     * what stops an Admin from touching a Super Admin.
     */
    public function outranksOrMatches(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * @param  Builder<covariant self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }
}
