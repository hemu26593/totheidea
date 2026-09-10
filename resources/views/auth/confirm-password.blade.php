<x-layouts.guest title="Confirm password">
    <h2 class="text-base font-semibold">Confirm your password</h2>
    <p class="mt-1 text-sm text-slate-500">
        This action is sensitive. Please confirm your password to continue.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-5 flex flex-col gap-4">
        @csrf

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
            @error('password')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Confirm
        </button>
    </form>
</x-layouts.guest>
