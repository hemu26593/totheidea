<x-layouts.guest title="Reset password">
    <h2 class="text-base font-semibold">Reset your password</h2>
    <p class="mt-1 text-sm text-ink-3">
        We'll email a reset link if the address belongs to an account.
    </p>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-success-wash px-3 py-2 text-sm text-success ring-1 ring-success-line">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-5 flex flex-col gap-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-ink-2">Email</label>
            <x-ui.input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}" class="mt-1" />
            @error('email')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">Email reset link</x-ui.button>
    </form>

    <p class="mt-4 text-center text-sm">
        <a href="{{ route('login') }}" class="text-ink-2 underline hover:text-gold">Back to sign in</a>
    </p>
</x-layouts.guest>
