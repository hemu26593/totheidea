{{--
    The end of the road for this link.

    No dashboard, no customer record, no next form, no link anywhere into the
    application. The grant authorised one act, the act is done, and the page
    says so and stops.
--}}
<x-layouts.external title="Form submitted">
    <div class="rounded-lg bg-white p-6 text-center shadow-sm ring-1 ring-slate-200">
        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200">
            <span aria-hidden="true" class="text-lg leading-none">&check;</span>
        </div>

        <h1 class="mt-3 text-lg font-semibold tracking-tight">Thank you — your form has been submitted</h1>

        @php
            // Assembled in PHP rather than with inline directives: Blade only
            // recognises a directive at a non-word boundary, so `received@if`
            // would compile the @endif and leave the @if as literal text.
            $what = $template?->name ?? 'Your form';
            $whose = $businessName ? ' for '.$businessName : '';
            $when = $submittedAt ? ' on '.$submittedAt->format('d M Y').' at '.$submittedAt->format('H:i') : '';
        @endphp

        <p class="mt-2 text-sm text-slate-600">
            {{ $what.$whose.' was received'.$when.'.' }}
        </p>

        <p class="mt-4 text-sm text-slate-500">
            Your programme team can see your answers now. There is nothing else you need to do.
        </p>

        <p class="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-500">
            This link has now been used and will not open the form again. If you need to change an
            answer, please contact your programme team.
        </p>
    </div>
</x-layouts.external>
