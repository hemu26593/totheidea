@props([
    'headings' => [],
])

{{-- min-w-0: see the note on ui.card. The scroll container must be allowed
     to be narrower than the table it holds. --}}
<div {{ $attributes->merge(['class' => 'min-w-0 overflow-x-auto rounded-lg bg-surface ring-1 ring-line']) }}>
    <table class="min-w-full divide-y divide-line text-sm">
        @if ($headings !== [])
            <thead class="bg-raised text-left text-xs uppercase tracking-wide text-ink-3">
                <tr>
                    @foreach ($headings as $heading)
                        <th scope="col" class="px-4 py-2.5 font-medium {{ str_starts_with((string) $heading, '>') ? 'text-right' : '' }}">
                            {{ ltrim((string) $heading, '>') }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody class="divide-y divide-line">{{ $slot }}</tbody>
    </table>
</div>
