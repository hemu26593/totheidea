<x-layouts.guest title="Sign in">
    <h2 class="text-base font-semibold">Sign in</h2>
    <p class="mt-1 text-sm text-ink-3">Accounts are created by an administrator.</p>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-success-wash px-3 py-2 text-sm text-success ring-1 ring-success-line">
            {{ session('status') }}
        </div>
    @endif

    @error('email')
        <div class="mt-4 rounded-md bg-danger-wash px-3 py-2 text-sm text-danger ring-1 ring-danger-line">
            {{ $message }}
        </div>
    @enderror

    <form method="POST" action="{{ route('login') }}" class="mt-5 flex flex-col gap-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-ink-2">Email</label>
            <x-ui.input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}" class="mt-1" />
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-ink-2">Password</label>
            <x-ui.input id="password" name="password" type="password" required autocomplete="current-password" class="mt-1" />
            @error('password')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        {{-- No "remember me": a long-lived auth cookie is a poor trade on an
             internal administrative tool. --}}

        <x-ui.button type="submit" variant="primary" class="w-full">Sign in</x-ui.button>
    </form>

    @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::resetPasswords()))
        <p class="mt-4 text-center text-sm">
            <a href="{{ route('password.request') }}" class="text-ink-2 underline hover:text-gold">
                Forgot your password?
            </a>
        </p>
    @endif
</x-layouts.guest>
