@props(['target' => null, 'label' => 'Working…'])

<span wire:loading @if ($target) wire:target="{{ $target }}" @endif
      {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs text-ink-3']) }}>
    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-gold" aria-hidden="true"></span>
    {{ $label }}
</span>
