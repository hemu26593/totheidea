<div class="max-w-lg">
    <h1 class="text-lg font-semibold">{{ $editing ? 'Edit user' : 'New user' }}</h1>
    <p class="text-sm text-ink-3">
        {{ $editing ? 'Update this account.' : 'Create an internal staff account.' }}
    </p>

    <form wire:submit="save" class="mt-6 flex flex-col gap-4 rounded-lg bg-surface p-6 shadow-sm ring-1 ring-line">
        <div>
            <label for="name" class="block text-sm font-medium text-ink-2">Name</label>
            <input id="name" type="text" wire:model="name"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-gold">
            @error('name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-ink-2">Email</label>
            <input id="email" type="email" wire:model="email"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-gold">
            @error('email') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        @unless ($editing)
            <div>
                <label for="password" class="block text-sm font-medium text-ink-2">Initial password</label>
                <input id="password" type="password" wire:model="password" autocomplete="new-password"
                       class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-gold">
                <p class="mt-1 text-xs text-ink-3">At least 12 characters, with letters and numbers.</p>
                @error('password') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div>
            <label for="role" class="block text-sm font-medium text-ink-2">Role</label>
            <select id="role" wire:model="role"
                    class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-gold">
                @foreach ($assignableRoles as $assignable)
                    <option value="{{ $assignable->value }}">{{ $assignable->label() }}</option>
                @endforeach
            </select>
            {{-- This list is filtered for convenience only. The server re-checks
                 the rank rule on save, so submitting a role that is absent here
                 is rejected rather than accepted. --}}
            <p class="mt-1 text-xs text-ink-3">You can only assign roles up to your own level.</p>
            @error('role') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div class="mt-2 flex items-center gap-3">
            <button type="submit"
                    class="rounded-md bg-gold px-3 py-2 text-sm font-medium text-canvas transition hover:bg-gold-bright">
                {{ $editing ? 'Save changes' : 'Create user' }}
            </button>

            <a href="{{ route('users.index') }}" class="text-sm text-ink-2 hover:text-gold">Cancel</a>
        </div>
    </form>
</div>
