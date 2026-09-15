{{--
    One page for every refusal.

    A link that never existed, one that expired, one that was withdrawn, one
    already used and one belonging to another business all land here with the
    same words and the same status. Saying which would confirm to a stranger
    that a particular link, form or business exists.
--}}
<x-layouts.external title="Link not available">
    <div class="rounded-lg bg-surface p-6 text-center ring-1 ring-line">
        <h1 class="text-lg font-semibold tracking-tight text-ink">{{ $message }}</h1>

        <p class="mt-2 text-sm text-ink-2">
            Links to a form are personal, and they expire. If you were expecting this one to work,
            please contact your programme team and ask them to send a new one.
        </p>
    </div>
</x-layouts.external>
