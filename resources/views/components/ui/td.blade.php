@props(['align' => 'left', 'muted' => false])

<td {{ $attributes->merge(['class' => 'px-4 py-2.5 '.($align === 'right' ? 'text-right ' : '').($muted ? 'text-slate-500' : 'text-slate-800')]) }}>
    {{ $slot }}
</td>
