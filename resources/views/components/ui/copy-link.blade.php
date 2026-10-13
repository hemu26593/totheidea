@props([
    'url',
    'form' => null,
    'contact' => null,
    'expiresAt' => null,
    'delivered' => false,
])

{{--
    A freshly issued form link, shown to the operator who issued it.

    SHOWN ONCE. The plaintext token is not stored anywhere, so this block is
    the only chance anybody has to copy it; reload the page and it is gone,
    which is exactly the lifetime the token's plaintext has. Re-issuing is
    always possible, and always replaces the previous link.

    The copy control is an inline handler rather than a script block on
    purpose: a Livewire update replaces this markup without re-running scripts,
    and the link is just as usable by selecting the text if the clipboard API
    is unavailable.
--}}
<div {{ $attributes->merge(['class' => 'rounded-md bg-info-wash px-4 py-3 text-sm ring-1 ring-inset ring-info-line']) }}>
    <p class="font-semibold text-info">
        {{ $form ? $form.' link is ready' : 'Form link is ready' }}
    </p>

    <p class="mt-1 text-xs text-ink-2">
        @if ($delivered)
            Emailed to {{ $contact ?: 'the primary contact' }}. Copy it below if you want to send it yourself as well.
        @else
            No mail transport is configured, so nothing was emailed. Copy this link and send it to
            {{ $contact ?: 'the primary contact' }} yourself.
        @endif

        @if ($expiresAt)
            It expires on {{ $expiresAt }} and can be used once.
        @else
            It can be used once.
        @endif
    </p>

    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
        <label class="sr-only" for="issued-link-url">Form link</label>

        <input id="issued-link-url" type="text" readonly value="{{ $url }}" onclick="this.select()"
               class="block w-full min-w-0 rounded-md border-0 bg-raised px-3 py-2 font-mono text-xs text-ink ring-1 ring-inset ring-line">

        <div class="flex shrink-0 items-center gap-2">
            <button type="button" data-url="{{ $url }}"
                    onclick="var b=this;if(navigator.clipboard){navigator.clipboard.writeText(b.dataset.url).then(function(){b.textContent='Copied';});}else{document.getElementById('issued-link-url').select();}"
                    class="inline-flex items-center justify-center gap-1.5 rounded-md bg-gold px-3 py-2 text-sm font-medium text-canvas transition hover:bg-gold-bright">
                Copy link
            </button>

            <a href="mailto:{{ $contact }}?subject={{ rawurlencode(($form ?: 'Form').' — your link') }}&body={{ rawurlencode($url) }}"
               class="inline-flex items-center justify-center gap-1.5 rounded-md bg-raised px-3 py-2 text-sm font-medium text-ink-2 ring-1 ring-inset ring-line transition hover:bg-elevated hover:text-ink">
                Share by email
            </a>
        </div>
    </div>
</div>
