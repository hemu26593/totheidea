@props(['term'])

<div {{ $attributes->merge(['class' => 'py-2']) }}>
    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $term }}</dt>
    <dd class="mt-0.5 text-sm text-slate-800">{{ $slot }}</dd>
</div>
