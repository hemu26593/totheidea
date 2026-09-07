<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Exceptions\AuthorizationRuleException;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * User administration list.
 *
 * Every public method on a Livewire component is a directly invocable HTTP
 * endpoint, regardless of what the rendered UI offers. Authorization therefore
 * runs in mount() AND in every action — never only in the Blade template.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function deactivate(int $userId, UserService $users): void
    {
        $target = User::findOrFail($userId);

        $this->authorize('deactivate', $target);

        $this->runGuarded(fn () => $users->deactivate($target, $this->actor()));
    }

    public function activate(int $userId, UserService $users): void
    {
        $target = User::findOrFail($userId);

        $this->authorize('activate', $target);

        $this->runGuarded(fn () => $users->activate($target, $this->actor()));
    }

    public function delete(int $userId, UserService $users): void
    {
        $target = User::findOrFail($userId);

        $this->authorize('delete', $target);

        $this->runGuarded(function () use ($users, $target): void {
            $users->delete($target, $this->actor());
        });
    }

    public function render(): View
    {
        return view('livewire.users.index', [
            'users' => $this->results(),
            'roles' => UserRole::cases(),
        ])->layout('components.layouts.app', ['title' => 'Users']);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    private function results(): LengthAwarePaginator
    {
        return User::query()
            ->with('roles')
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace('%', '\%', $this->search).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * Surface a refused invariant as a form error rather than a 500.
     *
     * The policy already rejected the ordinary cases with a 403; reaching the
     * service guard means a race or a non-HTTP caller, which is worth showing
     * rather than swallowing.
     */
    private function runGuarded(callable $operation): void
    {
        try {
            $operation();
        } catch (AuthorizationRuleException $e) {
            $this->addError('user', $e->getMessage());

            return;
        }

        session()->flash('status', 'User updated.');
    }

    private function actor(): User
    {
        return auth()->user();
    }
}
