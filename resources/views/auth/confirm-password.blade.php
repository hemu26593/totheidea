<x-layouts.guest title="Confirm password">
    <h2 class="text-base font-semibold">Confirm your password</h2>
    <p class="mt-1 text-sm text-ink-3">
        This action is sensitive. Please confirm your password to continue.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-5 flex flex-col gap-4">
        @csrf

        <div>
            <label for="password" class="block text-sm font-medium text-ink-2">Password</label>
            <x-ui.input id="password" name="password" type="password" required autofocus autocomplete="current-password" class="mt-1" />
            @error('password')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">Confirm</x-ui.button>
    </form>
</x-layouts.guest>
