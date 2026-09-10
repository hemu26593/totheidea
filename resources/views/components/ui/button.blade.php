@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
    'size' => 'md',
])

@php
    $base = 'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition disabled:cursor-not-allowed disabled:opacity-50';

    $sizing = match ($size) {
        'sm' => 'px-2.5 py-1.5 text-xs',
        default => 'px-3 py-2 text-sm',
    };

    $look = match ($variant) {
        'primary' => 'bg-slate-900 text-white hover:bg-slate-700',
        'danger' => 'bg-rose-600 text-white hover:bg-rose-500',
        'ghost' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
        default => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "{$base} {$sizing} {$look}"]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "{$base} {$sizing} {$look}"]) }}>{{ $slot }}</button>
@endif
