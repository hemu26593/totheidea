<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">Users</h1>
            <p class="text-sm text-ink-3">Internal staff accounts.</p>
        </div>

        @can('create', App\Models\User::class)
            <a href="{{ route('users.create') }}"
               class="rounded-md bg-gold px-3 py-2 text-sm font-medium text-canvas transition hover:bg-gold-bright">
                New user
            </a>
        @endcan
    </div>

    @error('user')
        <div class="mt-4 rounded-md bg-danger-wash px-4 py-3 text-sm text-danger ring-1 ring-danger-line">
            {{ $message }}
        </div>
    @enderror

    <div class="mt-5 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or email"
               class="w-64 rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-gold">

        <select wire:model.live="status"
                class="rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-gold">
            <option value="all">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>

    <div class="mt-5 overflow-x-auto rounded-lg bg-surface shadow-sm ring-1 ring-line">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-raised text-left text-xs uppercase tracking-wide text-ink-3">
                <tr>
                    <th class="px-4 py-3 font-medium">Name</th>
                    <th class="px-4 py-3 font-medium">Email</th>
                    <th class="px-4 py-3 font-medium">Role</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium">Last sign-in</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                @forelse ($users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="px-4 py-3 font-medium">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $user->email }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $user->role()?->label() ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($user->is_active)
                                <span class="rounded-full bg-success-wash px-2 py-0.5 text-xs font-medium text-success ring-1 ring-success-line">Active</span>
                            @else
                                <span class="rounded-full bg-elevated px-2 py-0.5 text-xs font-medium text-ink-2 ring-1 ring-line">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-3">
                            {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                {{-- Each control is gated by the same policy the
                                     action re-checks server-side. --}}
                                @can('update', $user)
                                    <a href="{{ route('users.edit', $user) }}"
                                       class="rounded px-2 py-1 text-ink-2 hover:bg-elevated">Edit</a>
                                @endcan

                                @if ($user->is_active)
                                    @can('deactivate', $user)
                                        <button type="button" wire:click="deactivate({{ $user->id }})"
                                                class="rounded px-2 py-1 text-warning hover:bg-warning-wash">
                                            Deactivate
                                        </button>
                                    @endcan
                                @else
                                    @can('activate', $user)
                                        <button type="button" wire:click="activate({{ $user->id }})"
                                                class="rounded px-2 py-1 text-success hover:bg-success-wash">
                                            Activate
                                        </button>
                                    @endcan
                                @endif

                                @can('delete', $user)
                                    <button type="button" wire:click="delete({{ $user->id }})"
                                            wire:confirm="Permanently delete {{ $user->email }}?"
                                            class="rounded px-2 py-1 text-danger hover:bg-danger-wash">
                                        Delete
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-ink-3">No users match this filter.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
