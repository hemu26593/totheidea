<x-ui.workspace :customer="$customer" :tabs="$tabs" current="mmd"
                subtitle="Money, enquiries, sales and production, as actually recorded.">
    <x-slot:actions>
        @can('create', App\Models\MmdEntry::class)
            <x-ui.button size="sm" variant="primary" wire:click="startRecording">Record figures</x-ui.button>
        @endcan

        @if ($canSetTargets)
            <x-ui.button size="sm" wire:click="startTarget">Set target</x-ui.button>
        @endif
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.input type="date" wire:model.live="date" class="w-auto" />
        <x-ui.loading target="date" />
    </x-ui.filters>

    <x-ui.card title="Figures recorded on {{ \Carbon\CarbonImmutable::parse($date)->format('d M Y') }}"
               subtitle="{{ $entries->count() }} row(s) recorded for this day."
               class="mb-5"
               :padding="false">
        <x-ui.table :headings="array_merge(['Recorded'], array_map(fn ($m) => Str::headline($m), $metrics))"
                    class="rounded-none shadow-none ring-0">
            @forelse ($entries as $entry)
                <tr wire:key="mmd-entry-{{ $entry->id }}">
                    <x-ui.td muted class="whitespace-nowrap">
                        {{ $entry->recorded_at?->format('H:i') }}
                        @if ($entry->wasWrittenExternally())
                            <x-ui.badge tone="info" class="ml-1">External</x-ui.badge>
                        @endif
                    </x-ui.td>

                    @foreach ($metrics as $metric)
                        <x-ui.td align="right" class="tabular-nums">
                            @if ($entry->{$metric} === null)
                                <span class="text-ink-3">—</span>
                            @else
                                {{ in_array($metric, $moneyMetrics, true)
                                    ? number_format((float) $entry->{$metric}, 2)
                                    : number_format((float) $entry->{$metric}) }}
                            @endif
                        </x-ui.td>
                    @endforeach
                </tr>
            @empty
                <x-ui.empty-row :colspan="count($metrics) + 1"
                                title="Nothing recorded for this day"
                                description="A day may carry one row or several — the dashboard reads whatever is there." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card title="Weekly review — T / Th / S"
                   :subtitle="'Week beginning '.$weekStart->format('d M Y').'. Totals are summed across whatever rows exist.'"
                   :padding="false">
            <x-ui.table :headings="array_merge(['Day', 'Rows'], array_map(fn ($m) => Str::headline($m), $metrics))"
                        class="rounded-none shadow-none ring-0">
                @foreach ($reviewDays as $label => $isoDay)
                    @php $totals = $week[$label] ?? []; @endphp

                    <tr wire:key="review-{{ $label }}">
                        <x-ui.td class="font-medium">
                            {{ $label }}
                            @if (! ($recordedOn[$label] ?? false))
                                <span class="ml-1 text-xs font-normal text-ink-3">no entry</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td muted class="tabular-nums">{{ $totals['entry_count'] ?? 0 }}</x-ui.td>

                        @foreach ($metrics as $metric)
                            <x-ui.td align="right" class="tabular-nums">
                                @if (($totals[$metric] ?? null) === null)
                                    <span class="text-ink-3">—</span>
                                @else
                                    {{ in_array($metric, $moneyMetrics, true)
                                        ? number_format((float) $totals[$metric], 2)
                                        : number_format((float) $totals[$metric]) }}
                                @endif
                            </x-ui.td>
                        @endforeach
                    </tr>
                @endforeach
            </x-ui.table>

            <p class="px-4 py-3 text-xs text-ink-3">
                A missing entry is shown as missing, not as a failure: whether an absence on a review day
                counts against the business is an open client decision.
            </p>
        </x-ui.card>

        <x-ui.card title="Target vs actual" subtitle="Targets set by an administrator, against recorded figures."
                   :padding="false">
            <x-ui.table :headings="['Metric', 'Period', '>Target', '>Actual', '>Variance']"
                        class="rounded-none shadow-none ring-0">
                @forelse ($targets as $row)
                    @php $c = $row['comparison']; @endphp

                    <tr wire:key="target-{{ $row['target']->id }}">
                        <x-ui.td class="font-medium">{{ Str::headline($c['metric']) }}</x-ui.td>
                        <x-ui.td muted class="whitespace-nowrap text-xs">
                            {{ \Carbon\CarbonImmutable::parse($c['period_start'])->format('d M') }} –
                            {{ \Carbon\CarbonImmutable::parse($c['period_end'])->format('d M Y') }}
                        </x-ui.td>
                        <x-ui.td align="right" class="tabular-nums">{{ number_format($c['target'], 2) }}</x-ui.td>
                        <x-ui.td align="right" class="tabular-nums">
                            {{ $c['actual'] === null ? '—' : number_format($c['actual'], 2) }}
                        </x-ui.td>
                        <x-ui.td align="right" class="tabular-nums">
                            @if ($c['variance'] === null)
                                <span class="text-ink-3">—</span>
                            @else
                                <span class="{{ $c['variance'] < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ $c['variance'] > 0 ? '+' : '' }}{{ number_format($c['variance'], 2) }}
                                </span>
                            @endif
                        </x-ui.td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" title="No targets set"
                                    description="A target is an administrator's act; staff record figures against it." />
                @endforelse
            </x-ui.table>

            <p class="px-4 py-3 text-xs text-ink-3">
                Variance is the difference between two recorded numbers. It carries no grade or band —
                what a variance means has not been defined.
            </p>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$recording" title="Record MMD figures" close="$set('recording', false)">
        <form wire:submit="record" class="space-y-4">
            <p class="text-sm text-ink-2">
                For {{ \Carbon\CarbonImmutable::parse($date)->format('d M Y') }}. Leave a measure blank if it
                was not recorded — blank is not zero.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($metrics as $metric)
                    <x-ui.field :label="Str::headline($metric)" :error="$errors->first('figures.'.$metric)">
                        <x-ui.input type="number"
                                    step="{{ in_array($metric, $moneyMetrics, true) ? '0.01' : '1' }}"
                                    min="0"
                                    wire:model="figures.{{ $metric }}" />
                    </x-ui.field>
                @endforeach
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('recording', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Record</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$settingTarget" title="Set a target" close="$set('settingTarget', false)">
        <form wire:submit="saveTarget" class="space-y-4">
            <x-ui.field label="Enrolment" required :error="$errors->first('targetEnrollmentId')">
                <x-ui.select wire:model="targetEnrollmentId">
                    <option value="">Choose…</option>
                    @foreach ($enrollments as $enrollment)
                        <option value="{{ $enrollment->id }}">{{ $enrollment->batch?->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Metric" required :error="$errors->first('targetMetric')">
                    <x-ui.select wire:model="targetMetric">
                        @foreach ($metrics as $metric)
                            <option value="{{ $metric }}">{{ Str::headline($metric) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Period type" required :error="$errors->first('targetPeriodType')">
                    <x-ui.select wire:model="targetPeriodType">
                        @foreach ($periodTypes as $type)
                            <option value="{{ $type }}">{{ Str::headline($type) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.field label="From" required :error="$errors->first('targetPeriodStart')">
                    <x-ui.input type="date" wire:model="targetPeriodStart" />
                </x-ui.field>

                <x-ui.field label="To" required :error="$errors->first('targetPeriodEnd')">
                    <x-ui.input type="date" wire:model="targetPeriodEnd" />
                </x-ui.field>

                <x-ui.field label="Target" required :error="$errors->first('targetValue')">
                    <x-ui.input type="number" step="0.01" min="0" wire:model="targetValue" />
                </x-ui.field>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('settingTarget', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Set target</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
