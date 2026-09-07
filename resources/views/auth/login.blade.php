<x-layouts.guest title="Sign in">
    <h2 class="text-base font-semibold">Sign in</h2>
    <p class="mt-1 text-sm text-slate-500">Accounts are created by an administrator.</p>

    @if (session('status'))
        <div class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    @error('email')
        <div class="mt-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">
            {{ $message }}
        </div>
    @enderror

    <form method="POST" action="{{ route('login') }}" class="mt-5 flex flex-col gap-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="username"
                   value="{{ old('email') }}"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900">
            @error('password')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- No "remember me": a long-lived auth cookie is a poor trade on an
             internal administrative tool. --}}

        <button type="submit"
                class="mt-1 w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            Sign in
        </button>
    </form>

    @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::resetPasswords()))
        <p class="mt-4 text-center text-sm">
            <a href="{{ route('password.request') }}" class="text-slate-600 underline hover:text-slate-900">
                Forgot your password?
            </a>
        </p>
    @endif
</x-layouts.guest>
