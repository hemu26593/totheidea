@props(['align' => 'left', 'muted' => false])

<td {{ $attributes->merge(['class' => 'px-4 py-2.5 '.($align === 'right' ? 'text-right ' : '').($muted ? 'text-ink-3' : 'text-ink-2')]) }}>
    {{ $slot }}
</td>
