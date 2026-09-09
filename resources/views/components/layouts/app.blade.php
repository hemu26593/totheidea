@php
    $sections = \App\Support\Navigation::sections();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
{{--
    The mobile drawer is a checkbox and sibling selectors, not a script. The
    build is required to stay offline-capable, and a navigation that depends on
    JavaScript having loaded is a navigation that can fail to open.
--}}
<div class="min-h-full lg:flex">
    <input type="checkbox" id="bmp-nav" class="peer sr-only" aria-label="Toggle navigation">

    <label for="bmp-nav"
           class="fixed inset-0 z-30 hidden bg-slate-900/40 peer-checked:block lg:peer-checked:hidden"
           aria-hidden="true"></label>

    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 -translate-x-full flex-col overflow-y-auto border-r border-slate-200 bg-white transition-transform peer-checked:translate-x-0 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <a href="{{ route('dashboard') }}" class="text-sm font-semibold tracking-tight">
                {{ config('app.name') }}
            </a>

            <label for="bmp-nav"
                   class="cursor-pointer rounded px-1.5 text-lg leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700 lg:hidden">
                &times;<span class="sr-only">Close navigation</span>
            </label>
        </div>

        {{-- Visibility here is convenience. Every destination re-authorizes
             itself on mount; see App\Support\Navigation. --}}
        <nav class="flex-1 space-y-5 px-3 py-4" aria-label="Main">
            @foreach ($sections as $section)
                <div>
                    <p class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ $section['label'] }}
                    </p>

                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            <li>
                                <a href="{{ $item['url'] }}"
                                   @if ($item['active']) aria-current="page" @endif
                                   class="block rounded-md px-2 py-1.5 text-sm transition
                                          {{ $item['active']
                                              ? 'bg-slate-900 font-medium text-white'
                                              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-slate-200 px-4 py-3">
            <p class="truncate text-sm font-medium text-slate-800">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>

            <div class="mt-2 flex items-center justify-between gap-2">
                <x-ui.badge>{{ auth()->user()->role()?->label() ?? 'No role' }}</x-ui.badge>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs text-slate-500 hover:text-slate-900">Sign out</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5 lg:hidden">
            <label for="bmp-nav"
                   class="cursor-pointer rounded-md p-1.5 text-slate-600 ring-1 ring-inset ring-slate-300">
                <span class="block h-0.5 w-4 bg-current"></span>
                <span class="mt-1 block h-0.5 w-4 bg-current"></span>
                <span class="mt-1 block h-0.5 w-4 bg-current"></span>
                <span class="sr-only">Open navigation</span>
            </label>

            <span class="truncate text-sm font-semibold">{{ $title ?? 'Dashboard' }}</span>
        </header>

        <main class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <x-ui.flash />
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
