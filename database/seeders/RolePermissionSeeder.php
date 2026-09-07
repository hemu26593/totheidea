<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconciles roles and permissions to config/authorization.php (ADR-010).
 *
 * Idempotent: safe to run on every deploy. Permissions removed from the config
 * are removed from the database, so the config is genuinely the source of
 * truth rather than merely the starting point.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Spatie caches the permission map aggressively. Without this reset the
        // seeder can read a stale map and write the wrong grants — the failure
        // mode is authorization that is correct in tests and wrong in
        // production.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $defined = $this->definedPermissions();

            $this->syncPermissions($defined);
            $this->syncRoles($defined);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, string>
     */
    private function definedPermissions(): array
    {
        $grouped = (array) config('authorization.permissions', []);

        return array_values(array_unique(array_merge(...array_values($grouped))));
    }

    /**
     * @param  array<int, string>  $defined
     */
    private function syncPermissions(array $defined): void
    {
        foreach ($defined as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Drop permissions no longer defined, so a rename does not leave an
        // orphaned grant behind that still satisfies a stale check somewhere.
        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $defined)
            ->delete();
    }

    /**
     * @param  array<int, string>  $defined
     */
    private function syncRoles(array $defined): void
    {
        $map = (array) config('authorization.roles', []);

        foreach (UserRole::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');

            if ($role === UserRole::SuperAdmin) {
                // Super Admin holds no stored grants: Gate::before grants
                // everything, so stored permissions would be a second source of
                // truth that drifts as permissions are added (ADR-008).
                $model->syncPermissions([]);

                continue;
            }

            $granted = array_values(array_intersect($map[$role->value] ?? [], $defined));

            $model->syncPermissions($granted);
        }

        // Exactly three roles exist (ADR-008); remove anything else.
        Role::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', UserRole::values())
            ->delete();
    }
}
