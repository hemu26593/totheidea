@props([
    'label',
    'value',
    'hint' => null,
    'href' => null,
    'tone' => 'default',
])

@php
    // The figure itself is white by default. A tone is applied only when the
    // number MEANS something - overdue, failing, complete - so colour on a
    // metric always carries information.
    $toneClass = match ($tone) {
        'warning' => 'text-warning',
        'danger' => 'text-danger',
        'success' => 'text-success',
        default => 'text-ink',
    };
@endphp

<{{ $href ? 'a' : 'div' }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'block rounded-lg bg-surface p-4 ring-1 ring-line '.($href ? 'transition hover:ring-gold-line hover:bg-elevated' : '')]) }}>
    <p class="text-xs font-medium uppercase tracking-wide text-ink-3">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $toneClass }}">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-ink-3">{{ $hint }}</p>
    @endif
</{{ $href ? 'a' : 'div' }}>
