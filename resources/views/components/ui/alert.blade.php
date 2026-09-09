@props([
    'tone' => 'info',
    'title' => null,
])

@php
    $classes = match ($tone) {
        'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'danger' => 'bg-rose-50 text-rose-800 ring-rose-200',
        default => 'bg-sky-50 text-sky-900 ring-sky-200',
    };
@endphp

<div {{ $attributes->merge(['class' => "rounded-md px-4 py-3 text-sm ring-1 ring-inset {$classes}"]) }}>
    @if ($title)
        <p class="font-semibold">{{ $title }}</p>
    @endif

    <div class="{{ $title ? 'mt-1' : '' }}">{{ $slot }}</div>
</div>
