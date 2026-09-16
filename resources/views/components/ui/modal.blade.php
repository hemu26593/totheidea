@props([
    'show' => false,
    'title' => null,
    'close' => null,
])

{{--
    A modal is presentation. The action behind it is authorized server-side in
    the Livewire component, so dismissing or bypassing this dialog changes
    nothing about what is permitted.
--}}
@if ($show)
    <div class="fixed inset-0 z-40 overflow-y-auto" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-canvas/80"
             @if ($close) wire:click="{{ $close }}" @endif
             aria-hidden="true"></div>

        <div class="relative flex min-h-full items-center justify-center p-4">
            <div {{ $attributes->merge(['class' => 'w-full max-w-lg rounded-lg bg-raised shadow-2xl shadow-black/60 ring-1 ring-line-strong']) }}>
                @if ($title)
                    <header class="border-b border-line px-5 py-3">
                        <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>
                    </header>
                @endif

                <div class="px-5 py-4">{{ $slot }}</div>

                @isset($footer)
                    <footer class="flex items-center justify-end gap-2 border-t border-line px-5 py-3">
                        {{ $footer }}
                    </footer>
                @endisset
            </div>
        </div>
    </div>
@endif
