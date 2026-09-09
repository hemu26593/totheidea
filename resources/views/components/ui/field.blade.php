@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="block text-xs font-medium text-slate-700">
            {{ $label }}
            @if ($required)
                <span class="text-rose-600" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint && ! $error)
        <p class="text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="text-xs text-rose-600">{{ $error }}</p>
    @endif
</div>
