<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The internal programme team: the people who conduct sessions and review work.
 *
 * These are staff logins, so that a demonstration shows sessions conducted by
 * somebody and assignments reviewed by somebody, rather than by a blank. Their
 * passwords are random and are never printed: nobody is meant to sign in as
 * them, and a demo account with a known password is exactly the thing that
 * escapes a demo environment.
 *
 * The Super Admin is not touched. It is created by SuperAdminSeeder, from
 * BMP_SUPER_ADMIN_PASSWORD, and that remains the only account anybody signs in
 * with.
 */
class DemoTeamSeeder extends Seeder
{
    public function run(): void
    {
        $actor = $this->superAdmin();
        $users = app(UserService::class);

        foreach (DemoDataset::team() as $member) {
            if (User::query()->where('email', $member['email'])->exists()) {
                continue;
            }

            $users->create(
                [
                    'name' => $member['name'],
                    // Random, never shown, never reused. These accounts exist
                    // to own records, not to be signed in to.
                    'password' => Hash::make(Str::password(40)),
                    'email' => $member['email'],
                ],
                UserRole::from($member['role']),
                $actor,
            );
        }
    }

    /**
     * The account every demo write is attributed to.
     */
    public static function actor(): User
    {
        return (new self)->superAdmin();
    }

    /**
     * Staff who conduct sessions, in a stable order so a reseed produces the
     * same allocation rather than a different one each time.
     *
     * @return list<User>
     */
    public static function consultants(): array
    {
        // Matched on the exact addresses rather than a LIKE pattern: the demo
        // team is a known list, and a bare LIKE would depend on the engine's
        // default escape character, which this codebase does not allow.
        $consultants = User::query()
            ->whereIn('email', array_column(DemoDataset::team(), 'email'))
            ->orderBy('id')
            ->get()
            ->all();

        return $consultants === [] ? [self::actor()] : $consultants;
    }

    private function superAdmin(): User
    {
        $email = (string) config('authorization.initial_super_admin.email');

        $actor = User::query()->where('email', $email)->first()
            ?? User::query()->orderBy('id')->first();

        if ($actor === null) {
            throw new RuntimeException(
                'The demo dataset needs an existing user to attribute its writes to. '
                .'Run `php artisan db:seed` first so the Super Admin exists.'
            );
        }

        return $actor;
    }
}
