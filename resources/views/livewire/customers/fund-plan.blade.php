<x-ui.workspace :customer="$customer" :tabs="$tabs" current="fund-plan"
                subtitle="One month's money plan: in, out, marketing, sales closing and production.">
    <x-slot:actions>
        @if ($plan && $plan->status === App\Models\FundPlan::STATUS_DRAFT)
            @can('approve', $plan)
                <x-ui.button size="sm" variant="primary" wire:click="approve"
                             wire:confirm="Approve this month's fund plan? It closes to further lines.">
                    Approve plan
                </x-ui.button>
            @endcan
        @endif
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.select wire:model.live="enrollmentId" class="w-auto">
            @foreach ($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}">{{ $enrollment->batch?->name }} ({{ $enrollment->batch?->code }})</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wire:model.live="month" class="w-auto">
            @for ($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}">{{ \Carbon\CarbonImmutable::create(2000, $m, 1)->format('F') }}</option>
            @endfor
        </x-ui.select>

        <x-ui.input type="number" wire:model.live="year" min="2000" max="2100" class="w-28" />

        <x-ui.loading target="enrollmentId,month,year" />
    </x-ui.filters>

    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty title="Not enrolled"
                        description="A fund plan belongs to an enrolment. Enrol this business in a batch first." />
        </x-ui.card>
    @elseif ($plan === null)
        <x-ui.card>
            <x-ui.empty title="No plan for this month"
                        description="Create the month, then add money-in, money-out, marketing, sales-closing and production lines to it.">
                <x-slot:actions>
                    @can('create', App\Models\FundPlan::class)
                        <x-ui.button variant="primary" wire:click="createMonth">Create month</x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.empty>
        </x-ui.card>
    @else
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <x-ui.status-badge :status="$plan->status" />

            @if ($plan->status === App\Models\FundPlan::STATUS_APPROVED)
                <span class="text-xs text-ink-3">
                    Approved {{ $plan->approved_at?->format('d M Y') }} — closed to further lines.
                </span>
            @endif
        </div>

        <div class="space-y-5">
            @foreach ($sections as $section)
                @php $lines = collect($bySection[$section->value] ?? []); @endphp

                <x-ui.card wire:key="section-{{ $section->value }}" :padding="false">
                    <x-slot:title>{{ Str::headline($section->value) }}</x-slot:title>

                    <x-slot:actions>
                        {{-- Authorized against the plan: a line carries no
                             permission of its own. --}}
                        @if ($plan->status === App\Models\FundPlan::STATUS_DRAFT)
                            @can('update', $plan)
                                <x-ui.button size="sm" wire:click="startLine('{{ $section->value }}')">Add line</x-ui.button>
                            @endcan
                        @endif
                    </x-slot:actions>

                    <x-ui.table :headings="['Label', 'Week', 'Planning type', 'Due', '>Planned', '>Actual']"
                                class="rounded-none shadow-none ring-0">
                        @forelse ($lines as $line)
                            <tr wire:key="line-{{ $line->id }}">
                                <x-ui.td>{{ $line->label ?? '—' }}</x-ui.td>
                                <x-ui.td muted>{{ $line->week_number ? 'Week '.$line->week_number : 'Month' }}</x-ui.td>
                                <x-ui.td muted>
                                    @if ($line->planning_type)
                                        <x-ui.badge>{{ $line->planning_type->value }}</x-ui.badge>
                                        <span class="ml-1 text-xs">{{ $line->planning_type->label() }}</span>
                                    @else
                                        —
                                    @endif
                                </x-ui.td>
                                <x-ui.td muted>
                                    @if ($line->due_classification)
                                        <x-ui.badge tone="info">{{ $line->due_classification->value }}</x-ui.badge>
                                    @else
                                        —
                                    @endif
                                </x-ui.td>
                                <x-ui.td align="right" class="tabular-nums">
                                    {{ $line->planned_amount !== null ? number_format((float) $line->planned_amount, 2) : '—' }}
                                </x-ui.td>
                                <x-ui.td align="right" class="tabular-nums">
                                    {{ $line->actual_amount !== null ? number_format((float) $line->actual_amount, 2) : '—' }}
                                </x-ui.td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="6" title="No lines in this section" />
                        @endforelse

                        @if ($lines->isNotEmpty())
                            <tr class="bg-raised font-medium">
                                <x-ui.td colspan="4">Section total</x-ui.td>
                                <x-ui.td align="right" class="tabular-nums">
                                    {{ number_format((float) $lines->sum(fn ($l) => (float) ($l->planned_amount ?? 0)), 2) }}
                                </x-ui.td>
                                <x-ui.td align="right" class="tabular-nums">
                                    {{ number_format((float) $lines->sum(fn ($l) => (float) ($l->actual_amount ?? 0)), 2) }}
                                </x-ui.td>
                            </tr>
                        @endif
                    </x-ui.table>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <x-ui.modal :show="$addingLine" title="Add a line" close="$set('addingLine', false)">
        <form wire:submit="addLine" class="space-y-4">
            <x-ui.field label="Section" required :error="$errors->first('section')">
                <x-ui.select wire:model.live="section">
                    @foreach ($sections as $s)
                        <option value="{{ $s->value }}">{{ Str::headline($s->value) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Label" :error="$errors->first('label')">
                <x-ui.input wire:model="label" />
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Week" hint="Blank = a month-level line." :error="$errors->first('weekNumber')">
                    <x-ui.select wire:model="weekNumber">
                        <option value="">Month</option>
                        @for ($w = 1; $w <= 5; $w++)
                            <option value="{{ $w }}">Week {{ $w }}</option>
                        @endfor
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Planning type" :error="$errors->first('planningType')">
                    <x-ui.select wire:model="planningType">
                        <option value="">—</option>
                        @foreach ($planningTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->value }} — {{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            {{-- Only valid on fund-in lines. The service refuses it elsewhere
                 (invariant I6), so the field is offered only where it applies. --}}
            @if ($section === 'fund_in')
                <x-ui.field label="Due classification"
                            hint="Valid only on money-in lines."
                            :error="$errors->first('dueClassification')">
                    <x-ui.select wire:model="dueClassification">
                        <option value="">—</option>
                        @foreach ($dueClassifications as $due)
                            <option value="{{ $due->value }}">{{ $due->value }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Planned amount" :error="$errors->first('plannedAmount')">
                    <x-ui.input type="number" step="0.01" min="0" wire:model="plannedAmount" />
                </x-ui.field>

                <x-ui.field label="Actual amount" :error="$errors->first('actualAmount')">
                    <x-ui.input type="number" step="0.01" min="0" wire:model="actualAmount" />
                </x-ui.field>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('addingLine', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add line</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
