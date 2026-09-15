@props(['model' => 'search', 'placeholder' => 'Search…'])

<input type="search"
       wire:model.live.debounce.300ms="{{ $model }}"
       placeholder="{{ $placeholder }}"
       aria-label="{{ $placeholder }}"
       {{ $attributes->merge(['class' => 'w-full rounded-md border-0 bg-raised px-3 py-2 text-sm text-ink ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold sm:w-72']) }}>
