<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three V1 roles (ADR-008).
 *
 * The rank is not a permission and is never stored on the user. It exists only
 * to express the privilege hierarchy used by role assignment and by the rules
 * governing which users an actor may act upon (ADR-012).
 */
enum UserRole: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Staff => 'Staff',
        };
    }

    /**
     * Higher rank means greater privilege. Compared, never persisted.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SuperAdmin => 3,
            self::Admin => 2,
            self::Staff => 1,
        };
    }

    public static function fromNullable(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
