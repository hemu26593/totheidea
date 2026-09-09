{{--
    The participant-facing shell.

    Deliberately NOT the internal layout. There is no sidebar, no navigation,
    no signed-in user, no sign-out, no links anywhere into the application:
    someone holding a link to one form must not be given a doorway to anything
    else, and there is nothing here for them to click that would offer one.

    It also renders with no authenticated user in scope, which the internal
    layout cannot do - components/layouts/app.blade.php reads auth()->user()
    directly and would fatal for a guest.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Form' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    <div class="flex min-h-full flex-col">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto w-full max-w-3xl px-4 py-4 sm:px-6">
                <p class="text-sm font-semibold tracking-tight">{{ config('app.name') }}</p>
                <p class="mt-0.5 text-xs text-slate-500">Business Mastery Programme</p>
            </div>
        </header>

        <main class="mx-auto w-full min-w-0 max-w-3xl flex-1 px-4 py-6 sm:px-6">
            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto w-full max-w-3xl px-4 py-4 text-xs text-slate-500 sm:px-6">
                This link was sent to you by your programme team. It opens this form only, and
                it expires. Please do not forward it.
            </div>
        </footer>
    </div>
</body>
</html>
