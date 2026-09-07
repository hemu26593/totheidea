<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Break-glass recovery: promote a user to Super Admin from the console.
 *
 * The safeguards in UserService deliberately make it impossible to remove the
 * last Super Admin over HTTP. If the invariant is ever violated anyway — a
 * restored backup, a bad migration, an account lost with the only holder —
 * there is no in-application route back in. This is that route.
 *
 * It runs outside the HTTP authorization stack by design, which means shell
 * access to the server is equivalent to Super Admin. Govern it accordingly.
 * The promotion is still audited.
 */
class PromoteSuperAdmin extends Command
{
    protected $signature = 'bmp:promote-super-admin {email : The email of the user to promote}';

    protected $description = 'Break-glass: grant the Super Admin role to an existing user';

    public function handle(AuditLogger $audit): int
    {
        $email = (string) $this->argument('email');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->isSuperAdmin() && $user->is_active) {
            $this->info("[{$email}] is already an active Super Admin.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Grant Super Admin to [{$email}]?", false)) {
            $this->comment('Aborted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $audit): void {
            $previous = $user->role();

            $user->syncRoles([UserRole::SuperAdmin->value]);
            $user->forceFill(['is_active' => true])->save();

            $audit->log(
                AuditAction::RoleAssigned,
                $user,
                ['role' => $previous?->value, 'via' => 'console'],
                ['role' => UserRole::SuperAdmin->value, 'via' => 'console'],
                $user,
            );
        });

        $this->info("[{$email}] is now an active Super Admin.");
        $this->warn('This action bypassed the HTTP authorization stack and has been audited.');

        return self::SUCCESS;
    }
}
