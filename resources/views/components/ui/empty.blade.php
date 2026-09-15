@props([
    'title' => 'Nothing here yet',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'px-4 py-10 text-center']) }}>
    <p class="text-sm font-medium text-ink-2">{{ $title }}</p>

    @if ($description)
        <p class="mx-auto mt-1 max-w-md text-sm text-ink-3">{{ $description }}</p>
    @endif

    @isset($actions)
        <div class="mt-4 flex justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
