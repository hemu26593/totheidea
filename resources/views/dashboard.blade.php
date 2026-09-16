<x-layouts.app title="Dashboard">
    <h1 class="text-lg font-semibold">Dashboard</h1>
    <p class="mt-1 text-sm text-ink-3">
        Signed in as {{ auth()->user()->email }}.
    </p>

    <div class="mt-6 rounded-lg bg-surface p-6 shadow-sm ring-1 ring-line">
        <p class="text-sm text-ink-2">
            This is the authentication and authorization foundation. BMP functionality
            — customers, batches, sessions, forms, assessments, reporting — is not
            built yet.
        </p>
    </div>
</x-layouts.app>
