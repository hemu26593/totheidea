@props(['term'])

<div {{ $attributes->merge(['class' => 'py-2']) }}>
    <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">{{ $term }}</dt>
    <dd class="mt-0.5 text-sm text-ink-2">{{ $slot }}</dd>
</div>
