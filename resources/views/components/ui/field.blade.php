@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="block text-xs font-medium text-ink-2">
            {{ $label }}
            @if ($required)
                <span class="text-danger" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint && ! $error)
        <p class="text-xs text-ink-3">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="text-xs text-danger">{{ $error }}</p>
    @endif
</div>
