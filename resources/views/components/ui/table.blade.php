@props([
    'headings' => [],
])

<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200']) }}>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        @if ($headings !== [])
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($headings as $heading)
                        <th scope="col" class="px-4 py-2.5 font-medium {{ str_starts_with((string) $heading, '>') ? 'text-right' : '' }}">
                            {{ ltrim((string) $heading, '>') }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody class="divide-y divide-slate-100">{{ $slot }}</tbody>
    </table>
</div>
