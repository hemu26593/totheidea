<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The sidebar, in one place.
 *
 * WHAT THIS IS NOT. Navigation is a convenience: a hidden link is not
 * authorization, and every destination re-authorizes itself on mount. What
 * this class prevents is a DEAD LINK - a nav entry an actor can see but not
 * open - by asking the same permission the route's `can:` middleware asks.
 *
 * Permissions are named, never roles. `hasRole('admin')` here would put a
 * role name in application code and would go stale the moment the permission
 * matrix moved.
 */
class Navigation
{
    /**
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return array_values(array_filter([
            self::section('Operations', [
                self::item('Dashboard', 'dashboard', null),
                self::item('Customers', 'customers.index', 'customers.view'),
                self::item('Batches', 'batches.index', 'batches.view'),
                self::item('Sessions', 'sessions.index', 'sessions.view'),
                self::item('Assignments', 'assignments.index', 'assignments.view'),
            ]),

            self::section('Delivery', [
                self::item('Attendance', 'attendance.index', 'attendance.view'),
                self::item('Reports', 'reports.index', 'reports.view'),
                self::item('Notifications', 'notifications.index', 'notifications.view'),
            ]),

            self::section('AI', [
                self::item('Generations', 'ai.generations', 'ai.analysis.view'),
                self::item('Prompt versions', 'ai.prompts', 'ai.analysis.view'),
            ]),

            self::section('Administration', [
                self::item('Users', 'users.index', 'users.view'),
                self::item('Programs', 'admin.programs', 'batches.view'),
                self::item('Form templates', 'admin.form-templates', 'forms.view'),
                self::item('Curriculum', 'admin.curriculum', 'sessions.view'),
                self::item('Skill areas', 'admin.skill-areas', 'forms.view'),
                self::item('Roles & permissions', 'admin.roles', 'roles.manage'),
            ]),
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $items
     * @return array{label: string, items: array<int, array<string, mixed>>}|null
     */
    private static function section(string $label, array $items): ?array
    {
        $visible = array_values(array_filter($items));

        return $visible === [] ? null : ['label' => $label, 'items' => $visible];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function item(string $label, string $route, ?string $permission): ?array
    {
        // A route that does not exist yet is simply not offered, rather than
        // throwing while rendering every page.
        if (! Route::has($route)) {
            return null;
        }

        if ($permission !== null && ! auth()->user()?->can($permission)) {
            return null;
        }

        return [
            'label' => $label,
            'route' => $route,
            'url' => route($route),
            'active' => self::isActive($route),
        ];
    }

    private static function isActive(string $route): bool
    {
        $current = (string) Route::currentRouteName();

        if ($current === $route) {
            return true;
        }

        // "customers.index" stays lit while a customer's own pages are open.
        $group = Str::beforeLast($route, '.');

        return $group !== $route && str_starts_with($current, $group.'.');
    }
}
