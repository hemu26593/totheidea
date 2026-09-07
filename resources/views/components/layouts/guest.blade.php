<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sign in' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    <div class="flex min-h-full flex-col justify-center px-6 py-12">
        <div class="mx-auto w-full max-w-sm">
            <h1 class="text-center text-xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
            <p class="mt-1 text-center text-sm text-slate-500">Internal platform</p>

            <div class="mt-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
