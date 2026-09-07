<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="text-sm font-semibold">{{ config('app.name') }}</a>

                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('dashboard') }}" class="text-slate-600 hover:text-slate-900">Dashboard</a>

                    {{-- Navigation visibility is convenience only; every route is
                         independently authorized server-side. --}}
                    @can('users.view')
                        <a href="{{ route('users.index') }}" class="text-slate-600 hover:text-slate-900">Users</a>
                    @endcan
                </nav>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <span class="text-slate-500">
                    {{ auth()->user()->name }}
                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                        {{ auth()->user()->role()?->label() ?? 'No role' }}
                    </span>
                </span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
