@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

<section {{ $attributes->merge(['class' => 'rounded-lg bg-white shadow-sm ring-1 ring-slate-200']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                @endif

                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $padding ? 'p-4' : '' }}">{{ $slot }}</div>
</section>
