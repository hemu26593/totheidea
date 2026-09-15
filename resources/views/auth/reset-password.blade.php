<x-layouts.guest title="Choose a new password">
    <h2 class="text-base font-semibold">Choose a new password</h2>

    <form method="POST" action="{{ route('password.update') }}" class="mt-5 flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="block text-sm font-medium text-ink-2">Email</label>
            <x-ui.input id="email" name="email" type="email" required autocomplete="username" value="{{ old('email', $request->email) }}" class="mt-1" />
            @error('email')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-ink-2">New password</label>
            <x-ui.input id="password" name="password" type="password" required autocomplete="new-password" class="mt-1" />
            <p class="mt-1 text-xs text-ink-3">At least 12 characters, with letters and numbers.</p>
            @error('password')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-ink-2">Confirm password</label>
            <x-ui.input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="mt-1" />
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">Update password</x-ui.button>
    </form>
</x-layouts.guest>
