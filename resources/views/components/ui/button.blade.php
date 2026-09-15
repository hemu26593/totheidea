@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
    'size' => 'md',
])

@php
    $base = 'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition '
        .'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold '
        .'disabled:cursor-not-allowed disabled:opacity-50';

    $sizing = match ($size) {
        'sm' => 'px-2.5 py-1.5 text-xs',
        default => 'px-3 py-2 text-sm',
    };

    // Gold is the ONE primary. Everything else recedes so that a screen with a
    // dozen controls still has a single obvious action on it.
    $look = match ($variant) {
        'primary' => 'bg-gold text-canvas hover:bg-gold-bright focus-visible:outline-gold',
        'danger' => 'bg-danger-wash text-danger ring-1 ring-inset ring-danger-line hover:bg-danger/20',
        'ghost' => 'text-ink-2 hover:bg-elevated hover:text-gold',
        default => 'bg-raised text-ink-2 ring-1 ring-inset ring-line hover:bg-elevated hover:text-ink',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "{$base} {$sizing} {$look}"]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "{$base} {$sizing} {$look}"]) }}>{{ $slot }}</button>
@endif
