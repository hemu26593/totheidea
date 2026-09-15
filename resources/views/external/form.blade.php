@php use App\Enums\QuestionType; @endphp

{{--
    The participant's view of one form.

    Every question, option and type comes from the form version the grant names
    - nothing about any particular instrument is written here, exactly as in the
    internal renderer. The two views differ only in how a control is wired: this
    one is a plain HTML form so it works without JavaScript, which matters for a
    recipient on an unknown device and network.

    Nothing internal is rendered: no ids, no staff, no scores, no other
    customer, no navigation.
--}}
<x-layouts.external :title="$template?->name ?? 'Form'">
    <div class="mb-5">
        <h1 class="text-lg font-semibold tracking-tight text-ink">{{ $template?->name ?? 'Form' }}</h1>

        @if ($businessName)
            <p class="mt-1 text-sm text-ink-2">For {{ $businessName }}</p>
        @endif

        @if ($template?->description)
            <p class="mt-2 text-sm text-ink-2">{{ $template->description }}</p>
        @endif
    </div>

    @if (session('external.saved'))
        <div class="mb-4 rounded-md bg-success-wash px-4 py-3 text-sm text-success ring-1 ring-inset ring-success-line">
            Your answers so far have been saved. You can close this page and come back to the same
            link to finish.
        </div>
    @endif

    @if ($missingRequired !== [])
        <div class="mb-4 rounded-md bg-warning-wash px-4 py-3 text-sm text-warning ring-1 ring-inset ring-warning-line">
            <p class="font-medium">Please answer these before submitting:</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($missingRequired as $label)
                    <li>{{ $label }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('external.forms.store', ['token' => $token]) }}"
          data-external-form class="space-y-5">
        @csrf

        @forelse ($sections as $section)
            <section class="min-w-0 rounded-lg bg-surface p-5 ring-1 ring-line">
                <h2 class="text-sm font-semibold text-ink">{{ $section->title }}</h2>

                @if ($section->description)
                    <p class="mt-1 text-sm text-ink-3">{{ $section->description }}</p>
                @endif

                <div class="mt-4 space-y-5">
                    @foreach ($section->questions as $question)
                        @php
                            $value = $answers[$question->id] ?? null;
                            $chosen = (array) ($selections[$question->id] ?? []);
                            $field = 'q-'.$question->id;
                        @endphp

                        <div class="border-b border-line pb-4 last:border-0 last:pb-0">
                            <label for="{{ $field }}" class="block text-sm font-medium text-ink-2">
                                {{ $question->label }}
                                @if ($question->is_required)
                                    <span class="text-danger" aria-hidden="true">*</span>
                                    <span class="sr-only">(required)</span>
                                @endif
                            </label>

                            @if ($question->help_text)
                                <p class="mt-0.5 text-xs text-ink-3">{{ $question->help_text }}</p>
                            @endif

                            <div class="mt-2">
                                @switch ($question->type)
                                    @case (QuestionType::Textarea)
                                        <textarea id="{{ $field }}" name="answers[{{ $question->id }}]" rows="3"
                                                  class="block w-full rounded-md border-0 bg-raised px-3 py-1.5 text-sm text-ink shadow-sm ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold">{{ $value }}</textarea>
                                        @break

                                    @case (QuestionType::Number)
                                    @case (QuestionType::Scale)
                                        <input id="{{ $field }}" type="number" step="any"
                                               name="answers[{{ $question->id }}]" value="{{ $value }}"
                                               class="block w-full rounded-md border-0 bg-raised px-3 py-1.5 text-sm text-ink shadow-sm ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold">
                                        @break

                                    @case (QuestionType::Date)
                                        <input id="{{ $field }}" type="date"
                                               name="answers[{{ $question->id }}]" value="{{ $value }}"
                                               class="block w-full rounded-md border-0 bg-raised px-3 py-1.5 text-sm text-ink shadow-sm ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold">
                                        @break

                                    @case (QuestionType::Boolean)
                                        <select id="{{ $field }}" name="answers[{{ $question->id }}]"
                                                class="block w-full rounded-md border-0 bg-raised px-3 py-1.5 text-sm text-ink shadow-sm ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold">
                                            <option value="">—</option>
                                            <option value="1" @selected($value === '1')>Yes</option>
                                            <option value="0" @selected($value === '0')>No</option>
                                        </select>
                                        @break

                                    @case (QuestionType::SelectOne)
                                        <div class="space-y-1.5">
                                            @foreach ($question->options as $option)
                                                <label class="flex items-start gap-2 text-sm text-ink-2">
                                                    <input type="radio" name="selections[{{ $question->id }}][]"
                                                           value="{{ $option->id }}"
                                                           @checked(in_array($option->id, $chosen, false))
                                                           class="mt-0.5 border-line bg-raised text-gold focus:ring-gold">
                                                    <span>
                                                        {{ $option->label }}
                                                        @if ($option->label_secondary)
                                                            <span class="text-ink-3">({{ $option->label_secondary }})</span>
                                                        @endif
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @break

                                    @case (QuestionType::SelectMany)
                                        <div class="space-y-1.5">
                                            @foreach ($question->options as $option)
                                                <label class="flex items-start gap-2 text-sm text-ink-2">
                                                    <input type="checkbox" name="selections[{{ $question->id }}][]"
                                                           value="{{ $option->id }}"
                                                           @checked(in_array($option->id, $chosen, false))
                                                           class="mt-0.5 rounded border-line bg-raised text-gold focus:ring-gold">
                                                    <span>{{ $option->label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @break

                                    @default
                                        <input id="{{ $field }}" type="text"
                                               name="answers[{{ $question->id }}]" value="{{ $value }}"
                                               class="block w-full rounded-md border-0 bg-raised px-3 py-1.5 text-sm text-ink shadow-sm ring-1 ring-inset ring-line placeholder:text-ink-3 focus:ring-2 focus:ring-inset focus:ring-gold">
                                @endswitch
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="rounded-lg bg-surface p-5 text-sm text-ink-3 ring-1 ring-line">
                There are no questions to answer on this form.
            </section>
        @endforelse

        {{--
            The loading state is the browser's own: submit is disabled the
            moment the form is posted, so a slow connection cannot turn into a
            double submission. Written inline because the page must work with
            no build step and no framework runtime.
        --}}
        {{--
            The action travels in a hidden field, not on the buttons.

            A submit button IS the submitter, and a submitter that has been
            disabled is dropped from the payload - so disabling the buttons to
            show a loading state would silently turn "Submit form" into "save
            and stay". Carrying the action separately lets the buttons be
            disabled for the participant's benefit without changing what they
            asked for. Without JavaScript the default below still posts, which
            is why it is the safe one: saving, never submitting.
        --}}
        <input type="hidden" name="action" value="save" data-action-field>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <button type="submit" value="save" data-action="save"
                    class="rounded-md bg-raised px-3 py-2 text-sm font-medium text-ink-2 ring-1 ring-inset ring-line transition hover:bg-elevated hover:text-ink">
                Save and finish later
            </button>

            <button type="submit" name="action" value="submit" data-action="submit"
                    class="rounded-md bg-gold px-3 py-2 text-sm font-medium text-canvas transition hover:bg-gold-bright">
                Submit form
            </button>
        </div>

        <p class="text-right text-xs text-ink-3" data-pending-note hidden>Sending…</p>
    </form>

    <script>
        (function () {
            var form = document.querySelector('form[data-external-form]');

            if (!form) {
                return;
            }

            // Record which button was pressed BEFORE anything is disabled.
            form.querySelectorAll('button[data-action]').forEach(function (button) {
                button.addEventListener('click', function () {
                    form.querySelector('[data-action-field]').value = button.dataset.action;
                });
            });

            form.addEventListener('submit', function (event) {
                if (form.dataset.submitted === '1') {
                    event.preventDefault();

                    return;
                }

                form.dataset.submitted = '1';

                form.querySelectorAll('button[type=submit]').forEach(function (button) {
                    button.disabled = true;
                    button.classList.add('opacity-60', 'cursor-not-allowed');
                });

                form.querySelector('[data-pending-note]').hidden = false;
            });
        })();
    </script>
</x-layouts.external>
