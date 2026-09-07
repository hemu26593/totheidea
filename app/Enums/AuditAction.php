<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Auditable actions (ADR-013).
 *
 * This phase covers authentication and RBAC only. Customer and BMP domain
 * actions will extend this enum when those modules are built — the audit
 * architecture is deliberately reusable.
 */
enum AuditAction: string
{
    // Authentication
    case Login = 'auth.login';
    case Logout = 'auth.logout';
    case LoginFailed = 'auth.login_failed';
    case LoginBlockedInactive = 'auth.login_blocked_inactive';
    case PasswordReset = 'auth.password_reset';

    // User administration
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';

    // Roles and permissions
    case RoleAssigned = 'user.role_assigned';
    case RoleRevoked = 'user.role_revoked';
    case RolePermissionsChanged = 'role.permissions_changed';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::Logout => 'Signed out',
            self::LoginFailed => 'Failed sign-in',
            self::LoginBlockedInactive => 'Sign-in blocked (inactive)',
            self::PasswordReset => 'Password reset',
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::UserDeleted => 'User deleted',
            self::UserActivated => 'User activated',
            self::UserDeactivated => 'User deactivated',
            self::RoleAssigned => 'Role assigned',
            self::RoleRevoked => 'Role revoked',
            self::RolePermissionsChanged => 'Role permissions changed',
        };
    }

    /**
     * Security-significant actions, for filtering the audit view.
     */
    public function isSecurityEvent(): bool
    {
        return in_array($this, [
            self::RoleAssigned,
            self::RoleRevoked,
            self::RolePermissionsChanged,
            self::UserDeleted,
            self::UserDeactivated,
            self::LoginBlockedInactive,
        ], true);
    }
}
