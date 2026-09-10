<div class="max-w-lg">
    <h1 class="text-lg font-semibold">{{ $editing ? 'Edit user' : 'New user' }}</h1>
    <p class="text-sm text-slate-500">
        {{ $editing ? 'Update this account.' : 'Create an internal staff account.' }}
    </p>

    <form wire:submit="save" class="mt-6 flex flex-col gap-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div>
            <label for="name" class="block text-sm font-medium text-slate-700">Name</label>
            <input id="name" type="text" wire:model="name"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
            @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
            <input id="email" type="email" wire:model="email"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
            @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        @unless ($editing)
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Initial password</label>
                <input id="password" type="password" wire:model="password" autocomplete="new-password"
                       class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
                <p class="mt-1 text-xs text-slate-500">At least 12 characters, with letters and numbers.</p>
                @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div>
            <label for="role" class="block text-sm font-medium text-slate-700">Role</label>
            <select id="role" wire:model="role"
                    class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
                @foreach ($assignableRoles as $assignable)
                    <option value="{{ $assignable->value }}">{{ $assignable->label() }}</option>
                @endforeach
            </select>
            {{-- This list is filtered for convenience only. The server re-checks
                 the rank rule on save, so submitting a role that is absent here
                 is rejected rather than accepted. --}}
            <p class="mt-1 text-xs text-slate-500">You can only assign roles up to your own level.</p>
            @error('role') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="mt-2 flex items-center gap-3">
            <button type="submit"
                    class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                {{ $editing ? 'Save changes' : 'Create user' }}
            </button>

            <a href="{{ route('users.index') }}" class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
        </div>
    </form>
</div>
