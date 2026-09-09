<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * The permission matrix, READ-ONLY.
 *
 * config/authorization.php is the source of truth (ADR-010): roles are
 * explicit allowlists in version control, code-reviewed and diffable. Editing
 * them from a screen would move that decision out of review and into a click,
 * and would let a new permission reach a role nobody examined for it.
 *
 * SUPER ADMIN IS ABSENT FROM THE STORED GRANTS by design - it resolves through
 * Gate::before rather than rows, so a permission added later is covered
 * without a reseed. The guarded abilities are shown alongside, because they
 * are the exceptions that are NOT auto-granted and that is the least obvious
 * part of the model.
 */
class Roles extends Component
{
    public function mount(): void
    {
        if (! auth()->user()->can('roles.manage')) {
            abort(403);
        }
    }

    public function render(): View
    {
        /** @var array<string, array<int, string>> $groups */
        $groups = config('authorization.permissions', []);

        $granted = [];

        foreach (Role::query()->with('permissions')->get() as $role) {
            $granted[$role->name] = $role->permissions->pluck('name')->all();
        }

        return view('livewire.admin.roles', [
            'groups' => $groups,
            'roles' => UserRole::cases(),
            'granted' => $granted,
            'guardedAbilities' => (array) config('authorization.guarded_abilities', []),
        ])->layout('components.layouts.app', ['title' => 'Roles & permissions']);
    }
}
