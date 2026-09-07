<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the initial Super Admin.
 *
 * The password is never hard-coded. It is read from BMP_SUPER_ADMIN_PASSWORD,
 * or generated and printed once if that is unset — so a credential exists in
 * the environment or the operator's terminal, never in version control.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('BMP_SUPER_ADMIN_EMAIL', 'admin@example.test');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Super Admin [{$email}] already exists — skipping.");

            return;
        }

        $password = (string) env('BMP_SUPER_ADMIN_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20);
        }

        $user = User::create([
            'name' => (string) env('BMP_SUPER_ADMIN_NAME', 'Super Admin'),
            'email' => $email,
            'password' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(UserRole::SuperAdmin->value);

        $this->command?->info("Super Admin created: {$email}");

        if ($generated) {
            $this->command?->warn("Generated password (shown once): {$password}");
            $this->command?->warn('Change it after first sign-in.');
        }

        // Operational note: a single Super Admin cannot be demoted or
        // deactivated by anyone, because the last-Super-Admin guard refuses.
        // Provision a second one before relying on that guard in anger.
        $this->command?->comment('Provision a second Super Admin before going live.');
    }
}
