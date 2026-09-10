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
        <div class="fixed inset-0 bg-slate-900/40"
             @if ($close) wire:click="{{ $close }}" @endif
             aria-hidden="true"></div>

        <div class="relative flex min-h-full items-center justify-center p-4">
            <div {{ $attributes->merge(['class' => 'w-full max-w-lg rounded-lg bg-white shadow-xl ring-1 ring-slate-200']) }}>
                @if ($title)
                    <header class="border-b border-slate-200 px-5 py-3">
                        <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                    </header>
                @endif

                <div class="px-5 py-4">{{ $slot }}</div>

                @isset($footer)
                    <footer class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
                        {{ $footer }}
                    </footer>
                @endisset
            </div>
        </div>
    </div>
@endif
