<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Exceptions\AuthorizationRuleException;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create or edit an internal user.
 *
 * $userId is #[Locked] so a client cannot rebind it between requests to target
 * a different account than the one authorized in mount().
 */
class ManageUser extends Component
{
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = '';

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->authorize('update', $user);

            $this->userId = $user->getKey();
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->role()?->value ?? '';

            return;
        }

        $this->authorize('create', User::class);

        $this->role = UserRole::Staff->value;
    }

    public function save(UserService $users): mixed
    {
        $editing = $this->userId !== null;
        $target = $editing ? User::findOrFail($this->userId) : null;

        // Re-authorize on the action, not only on mount.
        $editing
            ? $this->authorize('update', $target)
            : $this->authorize('create', User::class);

        $data = $this->validate($this->rules($target));

        $selectedRole = UserRole::from($data['role']);
        $actor = auth()->user();

        // Role assignment is a separate authorization decision from editing
        // profile fields — this is the privilege-escalation boundary.
        $roleChanged = ! $editing || $target->role() !== $selectedRole;

        if ($roleChanged) {
            $this->authorize('assignRole', [$target ?? new User, $selectedRole]);
        }

        try {
            if ($editing) {
                $users->update($target, [
                    'name' => $data['name'],
                    'email' => $data['email'],
                ], $actor);

                if ($roleChanged) {
                    $users->assignRole($target, $selectedRole, $actor);
                }
            } else {
                $users->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ], $selectedRole, $actor);
            }
        } catch (AuthorizationRuleException $e) {
            $this->addError('role', $e->getMessage());

            return null;
        }

        session()->flash('status', $editing ? 'User updated.' : 'User created.');

        return $this->redirectRoute('users.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.users.manage-user', [
            'assignableRoles' => app(UserService::class)->assignableRoles(auth()->user()),
            'editing' => $this->userId !== null,
        ])->layout('components.layouts.app', ['title' => $this->userId ? 'Edit user' : 'New user']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $target): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target?->getKey()),
            ],
            'password' => $target === null
                ? ['required', 'string', Password::defaults()]
                : ['nullable'],
            // The role must be one the actor is actually allowed to assign;
            // validation mirrors the policy so the user sees a field error
            // rather than a 403 page.
            'role' => [
                'required',
                Rule::in(array_map(
                    fn (UserRole $role): string => $role->value,
                    app(UserService::class)->assignableRoles(auth()->user()),
                )),
            ],
        ];
    }
}
