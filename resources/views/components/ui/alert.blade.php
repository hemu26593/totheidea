@props([
    'tone' => 'info',
    'title' => null,
])

@php
    // A dark surface with a coloured edge and coloured text, not a slab of
    // colour: the message stays readable and the page stays calm.
    $classes = match ($tone) {
        'success' => 'bg-success-wash text-success ring-success-line',
        'warning' => 'bg-warning-wash text-warning ring-warning-line',
        'danger' => 'bg-danger-wash text-danger ring-danger-line',
        default => 'bg-info-wash text-info ring-info-line',
    };
@endphp

<div {{ $attributes->merge(['class' => "rounded-md px-4 py-3 text-sm ring-1 ring-inset {$classes}"]) }}>
    @if ($title)
        <p class="font-semibold">{{ $title }}</p>
    @endif

    <div class="{{ $title ? 'mt-1' : '' }}">{{ $slot }}</div>
</div>
