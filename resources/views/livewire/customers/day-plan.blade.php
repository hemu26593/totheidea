<x-ui.workspace :customer="$customer" :tabs="$tabs" current="day-plan"
                subtitle="One day's tasks and time slots. Distinct from the Time Grid and from the Action Plan.">
    <x-slot:actions>
        @can('create', App\Models\DayPlanItem::class)
            <x-ui.button size="sm" variant="primary" wire:click="startAdding">Add task</x-ui.button>
        @endcan
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.input type="date" wire:model.live="date" class="w-auto" />

        <x-ui.select wire:model.live="enrollmentId" class="w-auto">
            @foreach ($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}">
                    {{ $enrollment->batch?->name }} ({{ $enrollment->batch?->code }})
                </option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="date,enrollmentId" />
    </x-ui.filters>

    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty title="Not enrolled"
                        description="A day plan belongs to an enrolment. Enrol this business in a batch first." />
        </x-ui.card>
    @else
        <x-ui.table :headings="['Time', 'Task', 'G', 'C', 'M', 'Status', 'Source', '>Actions']">
            @forelse ($items as $item)
                <tr wire:key="day-item-{{ $item->id }}" class="hover:bg-elevated">
                    <x-ui.td muted class="whitespace-nowrap tabular-nums">
                        @if ($item->planned_start)
                            {{ substr((string) $item->planned_start, 0, 5) }}@if ($item->planned_end)–{{ substr((string) $item->planned_end, 0, 5) }}@endif
                        @else
                            —
                        @endif
                    </x-ui.td>

                    <x-ui.td>
                        <span class="{{ $item->isDone() ? 'text-ink-3 line-through' : 'font-medium' }}">
                            {{ $item->task }}
                        </span>
                    </x-ui.td>

                    {{-- G / C / M are the client's own column names, carried
                         verbatim. Their meaning is an open decision and is not
                         expanded here. --}}
                    <x-ui.td muted>{{ $item->g ?? '—' }}</x-ui.td>
                    <x-ui.td muted>{{ $item->c ?? '—' }}</x-ui.td>
                    <x-ui.td muted>{{ $item->m ?? '—' }}</x-ui.td>

                    <x-ui.td><x-ui.status-badge :status="$item->status" /></x-ui.td>

                    <x-ui.td muted>
                        @if ($item->carriedFrom)
                            <x-ui.badge tone="warning">
                                Carried forward
                                @if (($slipCounts[$item->id] ?? 0) > 1)
                                    ×{{ $slipCounts[$item->id] }}
                                @endif
                            </x-ui.badge>
                            <span class="ml-1 text-xs">
                                from {{ $item->carriedFrom->plan_date?->format('d M') }}
                            </span>
                        @else
                            <span class="text-xs">Planned here</span>
                        @endif
                    </x-ui.td>

                    <x-ui.td align="right">
                        @if ($item->isPlanned())
                            @can('complete', $item)
                                <x-ui.button size="sm" variant="primary"
                                             wire:click="complete({{ $item->id }})">Complete</x-ui.button>
                            @endcan
                        @endif
                    </x-ui.td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="8" title="Nothing planned for this day"
                                description="Add a task, or check another date." />
            @endforelse
        </x-ui.table>

        <p class="mt-4 text-xs text-ink-3">
            Unfinished tasks carry forward automatically through the domain's scheduled job. This screen
            shows where a task came from and how far it has slipped; it never creates a carried item itself.
        </p>
    @endif

    <x-ui.modal :show="$adding" title="Add a task" close="$set('adding', false)">
        <form wire:submit="add" class="space-y-4">
            <x-ui.field label="Task" required :error="$errors->first('task')">
                <x-ui.textarea wire:model="task" rows="2" />
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Planned start" :error="$errors->first('plannedStart')">
                    <x-ui.input type="time" wire:model="plannedStart" />
                </x-ui.field>

                <x-ui.field label="Planned end" :error="$errors->first('plannedEnd')">
                    <x-ui.input type="time" wire:model="plannedEnd" />
                </x-ui.field>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.field label="G" :error="$errors->first('g')">
                    <x-ui.input wire:model="g" />
                </x-ui.field>

                <x-ui.field label="C" :error="$errors->first('c')">
                    <x-ui.input wire:model="c" />
                </x-ui.field>

                <x-ui.field label="M" :error="$errors->first('m')">
                    <x-ui.input wire:model="m" />
                </x-ui.field>
            </div>

            <p class="text-xs text-ink-3">
                G, C and M are the worksheet's own column names, kept exactly as the client wrote them.
                What they stand for is not yet defined, so nothing is assumed about them here.
            </p>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('adding', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add task</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
