<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles and permissions, and clear Spatie's permission cache.
     *
     * The cache reset matters: Spatie caches the permission map across the
     * process, so without this a test can read grants seeded by an earlier
     * test and pass for the wrong reason.
     */
    protected function seedAuthorization(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function userWithRole(UserRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user->fresh();
    }

    protected function superAdmin(array $attributes = []): User
    {
        return $this->userWithRole(UserRole::SuperAdmin, $attributes);
    }

    protected function admin(array $attributes = []): User
    {
        return $this->userWithRole(UserRole::Admin, $attributes);
    }

    protected function staff(array $attributes = []): User
    {
        return $this->userWithRole(UserRole::Staff, $attributes);
    }
}
