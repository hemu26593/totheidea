<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sign in' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-canvas text-ink antialiased">
    <div class="flex min-h-full flex-col justify-center px-6 py-12">
        <div class="mx-auto w-full max-w-sm">
            <h1 class="text-center text-xl font-semibold tracking-tight text-gold">{{ config('app.name') }}</h1>
            <p class="mt-1 text-center text-[11px] uppercase tracking-[0.18em] text-ink-3">
                Growmatic Action
            </p>

            {{-- A single hairline of gold along the top edge: the whole brand
                 statement this screen needs. --}}
            <div class="mt-8 overflow-hidden rounded-lg bg-surface ring-1 ring-line">
                <div class="h-px bg-gold/50"></div>
                <div class="p-6">{{ $slot }}</div>
            </div>
        </div>
    </div>
</body>
</html>
