@props(['model' => 'search', 'placeholder' => 'Search…'])

<input type="search"
       wire:model.live.debounce.300ms="{{ $model }}"
       placeholder="{{ $placeholder }}"
       aria-label="{{ $placeholder }}"
       {{ $attributes->merge(['class' => 'w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-slate-900 sm:w-72']) }}>
