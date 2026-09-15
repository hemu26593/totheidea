@props(['type' => 'text'])

<input type="{{ $type }}"
       {{ $attributes->merge(['class' => 'block w-full rounded-md border-0 bg-raised px-3 py-2 text-sm text-ink ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold disabled:cursor-not-allowed disabled:bg-elevated disabled:text-ink-3 disabled:ring-line']) }}>
