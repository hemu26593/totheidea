@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

{{--
    min-w-0 is load-bearing, not cosmetic. A card is nearly always a grid or
    flex child, and such a child defaults to min-width:auto - so a wide table
    or <pre> inside it cannot shrink, and widens the whole document instead of
    scrolling within its own overflow container. Without this the page scrolls
    sideways on mobile and content is clipped off the right edge.
--}}
<section {{ $attributes->merge(['class' => 'min-w-0 rounded-lg bg-white shadow-sm ring-1 ring-slate-200']) }}>
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
