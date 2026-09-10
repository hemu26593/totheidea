<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the initial Super Admin.
 *
 * The password is never hard-coded. It is read from BMP_SUPER_ADMIN_PASSWORD,
 * or generated and printed once if that is unset — so a credential exists in
 * the environment or the operator's terminal, never in version control.
 *
 * READ THROUGH config(), NOT env(). `php artisan config:cache` stops Laravel
 * loading the .env file, so an env() call here would return its default from
 * that point on — and a deployment that caches config before seeding would
 * have quietly created the first administrator at the example address instead
 * of the operator's. Going through config/authorization.php means the cached
 * value is the configured one.
 *
 * In production the seeder REFUSES the example address rather than creating a
 * guessable administrator account. Failing the deploy is the cheap outcome
 * here; discovering it afterwards is not.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('authorization.initial_super_admin.email');

        if (app()->isProduction() && $email === 'admin@example.test') {
            throw new RuntimeException(
                'Refusing to seed the initial Super Admin at the example address in production. '
                .'Set BMP_SUPER_ADMIN_EMAIL (and BMP_SUPER_ADMIN_PASSWORD) before seeding, and run '
                .'config:cache after the environment is in place rather than before.'
            );
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Super Admin [{$email}] already exists — skipping.");

            return;
        }

        $password = (string) config('authorization.initial_super_admin.password');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20);
        }

        $user = User::create([
            'name' => (string) config('authorization.initial_super_admin.name'),
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
