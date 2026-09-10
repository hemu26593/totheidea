@props([
    'tabs' => [],
    'current' => null,
])

{{--
    $tabs: ['key' => ['label' => '…', 'url' => '…', 'count' => null]]

    A hidden tab is a convenience, never a control: each destination
    authorizes itself on mount.
--}}
{{-- flex-nowrap, not flex-wrap: sixteen tabs wrapped onto five rows on a
     narrow screen and pushed the content off the first viewport. A tab strip
     scrolls sideways within itself. --}}
<nav {{ $attributes->merge(['class' => '-mb-px flex flex-nowrap gap-x-1 overflow-x-auto border-b border-slate-200']) }}
     aria-label="Sections">
    @foreach ($tabs as $key => $tab)
        @php $active = $key === $current; @endphp

        <a href="{{ $tab['url'] }}"
           @if ($active) aria-current="page" @endif
           class="flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition
                  {{ $active
                      ? 'border-slate-900 text-slate-900'
                      : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800' }}">
            {{ $tab['label'] }}

            @if (($tab['count'] ?? null) !== null)
                <span class="rounded-full bg-slate-100 px-1.5 text-xs tabular-nums text-slate-600">{{ $tab['count'] }}</span>
            @endif
        </a>
    @endforeach
</nav>
