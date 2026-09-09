@props([
    'label',
    'value',
    'hint' => null,
    'href' => null,
    'tone' => 'default',
])

@php
    $toneClass = match ($tone) {
        'warning' => 'text-amber-700',
        'danger' => 'text-rose-700',
        'success' => 'text-emerald-700',
        default => 'text-slate-900',
    };
@endphp

<{{ $href ? 'a' : 'div' }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'block rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200 '.($href ? 'transition hover:ring-slate-400' : '')]) }}>
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $toneClass }}">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</{{ $href ? 'a' : 'div' }}>
