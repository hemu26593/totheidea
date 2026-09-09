@php use App\Enums\QuestionType; @endphp

<div>
    <x-ui.page-header :title="$template?->name ?? 'Form'"
                      :subtitle="'Version '.$version?->version_number.' — '.($submission->enrollment?->customer?->name ?? '')"
                      :breadcrumbs="array_filter([
                          'Customers' => route('customers.index'),
                          $submission->enrollment?->customer?->name => route('customers.show', $submission->customer_id),
                          'Forms' => route('customers.forms', $submission->customer_id),
                          ($template?->name ?? 'Form') => null,
                      ])">
        <x-slot:actions>
            <x-ui.status-badge :status="$submission->status" />

            @if ($submission->isDraft())
                @can('update', $submission)
                    <x-ui.button size="sm" variant="primary" wire:click="submit">Submit form</x-ui.button>
                @endcan
            @elseif ($template?->is_scored)
                @can('assessments.manage')
                    <x-ui.button size="sm" wire:click="score">Compute scores</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @unless ($submission->isDraft())
        <x-ui.alert tone="info" class="mb-4" title="Submitted">
            This submission was recorded on {{ $submission->submitted_at?->format('d M Y H:i') }} and is
            read-only. Amending it is a deliberate act that keeps the original on the record.
        </x-ui.alert>
    @endunless

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @forelse ($sections as $section)
                <x-ui.card wire:key="section-{{ $section->id }}"
                           :title="$section->title"
                           :subtitle="$section->description">
                    <div class="space-y-5">
                        @foreach ($section->questions as $question)
                            <div wire:key="question-{{ $question->id }}" class="border-b border-slate-100 pb-4 last:border-0 last:pb-0">
                                <x-ui.field :label="$question->label"
                                            :required="(bool) $question->is_required"
                                            :hint="$question->help_text">
                                    @php $disabled = ! $submission->isDraft(); @endphp

                                    @switch ($question->type)
                                        @case (QuestionType::Textarea)
                                            <x-ui.textarea wire:model="answers.{{ $question->id }}"
                                                           wire:blur="saveAnswer({{ $question->id }})"
                                                           :disabled="$disabled" rows="3" />
                                            @break

                                        @case (QuestionType::Number)
                                        @case (QuestionType::Scale)
                                            <x-ui.input type="number" step="any"
                                                        wire:model="answers.{{ $question->id }}"
                                                        wire:blur="saveAnswer({{ $question->id }})"
                                                        :disabled="$disabled" />
                                            @break

                                        @case (QuestionType::Date)
                                            <x-ui.input type="date"
                                                        wire:model="answers.{{ $question->id }}"
                                                        wire:change="saveAnswer({{ $question->id }})"
                                                        :disabled="$disabled" />
                                            @break

                                        @case (QuestionType::Boolean)
                                            <x-ui.select wire:model="answers.{{ $question->id }}"
                                                         wire:change="saveAnswer({{ $question->id }})"
                                                         :disabled="$disabled">
                                                <option value="">—</option>
                                                <option value="1">Yes</option>
                                                <option value="0">No</option>
                                            </x-ui.select>
                                            @break

                                        @case (QuestionType::SelectOne)
                                            <div class="space-y-1.5">
                                                @foreach ($question->options as $option)
                                                    <label class="flex items-start gap-2 text-sm text-slate-700">
                                                        <input type="radio"
                                                               value="{{ $option->id }}"
                                                               @checked(in_array($option->id, (array) ($selections[$question->id] ?? []), false))
                                                               wire:click="$set('selections.{{ $question->id }}', [{{ $option->id }}]); saveAnswer({{ $question->id }})"
                                                               @disabled($disabled)
                                                               class="mt-0.5 border-slate-300 text-slate-900 focus:ring-slate-900">
                                                        <span>
                                                            {{ $option->label }}
                                                            @if ($option->label_secondary)
                                                                <span class="text-slate-400">({{ $option->label_secondary }})</span>
                                                            @endif
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            @break

                                        @case (QuestionType::SelectMany)
                                            <div class="space-y-1.5">
                                                @foreach ($question->options as $option)
                                                    <label class="flex items-start gap-2 text-sm text-slate-700">
                                                        <input type="checkbox"
                                                               value="{{ $option->id }}"
                                                               wire:model="selections.{{ $question->id }}"
                                                               wire:change="saveAnswer({{ $question->id }})"
                                                               @disabled($disabled)
                                                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                                                        <span>{{ $option->label }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            @break

                                        @default
                                            <x-ui.input wire:model="answers.{{ $question->id }}"
                                                        wire:blur="saveAnswer({{ $question->id }})"
                                                        :disabled="$disabled" />
                                    @endswitch
                                </x-ui.field>

                                <x-ui.loading target="saveAnswer({{ $question->id }})" label="Saving…" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty title="This version has no questions"
                                description="A form version with no questions cannot be published, so this should not happen for a live instrument." />
                </x-ui.card>
            @endforelse
        </div>

        <div class="space-y-5">
            <x-ui.card title="Submission">
                <dl class="divide-y divide-slate-100">
                    <x-ui.definition term="Participant">
                        {{ $submission->enrollment?->customer?->name }}
                    </x-ui.definition>
                    <x-ui.definition term="Form version">
                        v{{ $version?->version_number }}
                        <x-ui.status-badge :status="$version?->status ?? 'draft'" class="ml-1" />
                    </x-ui.definition>
                    <x-ui.definition term="Status">
                        <x-ui.status-badge :status="$submission->status" />
                    </x-ui.definition>
                    <x-ui.definition term="Submitted">
                        {{ $submission->submitted_at?->format('d M Y H:i') ?? 'Not yet' }}
                    </x-ui.definition>
                    <x-ui.definition term="Source">
                        {{ $submission->source instanceof BackedEnum ? Str::headline($submission->source->value) : Str::headline((string) $submission->source) }}
                    </x-ui.definition>
                </dl>

                <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    A submission binds to the exact form version it was answered against, so what was asked
                    stays answerable even after the form moves on.
                </p>
            </x-ui.card>

            @if ($scores->isNotEmpty())
                <x-ui.card title="Scores" subtitle="Computed by the scoring service. Read-only here.">
                    <x-ui.table :headings="['Measure', '>Score']" class="shadow-none ring-0">
                        @foreach ($scores as $score)
                            <tr wire:key="score-{{ $score->id }}">
                                <x-ui.td>
                                    {{ $score->skillArea?->name ?? Str::headline($score->score_type) }}
                                    <span class="ml-1 text-xs text-slate-400">{{ $score->scheme_version }}</span>
                                </x-ui.td>
                                <x-ui.td align="right" class="tabular-nums">
                                    {{ rtrim(rtrim((string) $score->raw_score, '0'), '.') }}
                                    <span class="text-slate-400">/ {{ rtrim(rtrim((string) $score->max_score, '0'), '.') }}</span>
                                </x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    <p class="mt-3 text-xs text-slate-500">
                        No band or verdict is shown. Heat-map thresholds are undefined, and the
                        Average / Good / Better / Best values are categorical with no numeric mapping —
                        so neither is invented here.
                    </p>
                </x-ui.card>
            @elseif ($template?->is_scored && ! $submission->isDraft())
                <x-ui.card title="Scores">
                    <x-ui.empty title="Not scored yet"
                                description="Scoring is an explicit act with a recorded scheme version, not a side effect of submitting." />
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
