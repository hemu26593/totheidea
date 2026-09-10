@props([
    'title',
    'subtitle' => null,
    'breadcrumbs' => [],
])

<div {{ $attributes->merge(['class' => 'mb-6']) }}>
    @if ($breadcrumbs !== [])
        <nav class="mb-2 flex items-center gap-1.5 text-xs text-slate-500" aria-label="Breadcrumb">
            @foreach ($breadcrumbs as $label => $url)
                @if (! $loop->first)
                    <span class="text-slate-300" aria-hidden="true">/</span>
                @endif

                @if ($url && ! $loop->last)
                    <a href="{{ $url }}" class="hover:text-slate-900">{{ $label }}</a>
                @else
                    <span class="text-slate-700">{{ $label }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="truncate text-xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>

            @if ($subtitle)
                <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
