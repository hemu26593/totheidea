@props([
    'tone' => 'neutral',
])

@php
    // A wash behind the label rather than a solid block: on a dark surface a
    // filled badge shouts, and most of these are read in bulk down a table.
    // Each tone stays semantic - only 'accent' is the brand.
    $classes = match ($tone) {
        'success' => 'bg-success-wash text-success ring-success-line',
        'warning' => 'bg-warning-wash text-warning ring-warning-line',
        'danger' => 'bg-danger-wash text-danger ring-danger-line',
        'info' => 'bg-info-wash text-info ring-info-line',
        'accent' => 'bg-gold-wash-strong text-gold ring-gold-line',
        default => 'bg-elevated text-ink-2 ring-line',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $slot }}
</span>
