@props([
    'tone' => 'neutral',
])

@php
    $classes = match ($tone) {
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'accent' => 'bg-violet-50 text-violet-700 ring-violet-200',
        default => 'bg-slate-100 text-slate-600 ring-slate-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $slot }}
</span>
