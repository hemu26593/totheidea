<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Audits the authentication events Fortify does not route through
 * Fortify::authenticateUsing (ADR-013).
 *
 * Successful and failed sign-ins are recorded in FortifyServiceProvider, where
 * the outcome is actually decided.
 */
class RecordAuthenticationAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->audit->log(AuditAction::Logout, $user, actor: $user);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->audit->log(AuditAction::PasswordReset, $user, actor: $user);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Logout::class => 'handleLogout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
