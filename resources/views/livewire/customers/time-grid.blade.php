<x-ui.workspace :customer="$customer" :tabs="$tabs" current="time-grid"
                subtitle="Strategic allocation across the year, by quarter. This is not the Day Plan.">
    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.input type="number" wire:model.live="year" min="2000" max="2100" class="w-28" />

        <x-ui.select wire:model.live="enrollmentId" class="w-auto">
            @foreach ($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}">
                    {{ $enrollment->batch?->name }} ({{ $enrollment->batch?->code }})
                </option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="year,enrollmentId" />
    </x-ui.filters>

    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty title="Not enrolled"
                        description="The time grid belongs to an enrolment. Enrol this business in a batch first." />
        </x-ui.card>
    @else
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($quarters as $quarter)
                @php $entries = $byQuarter[$quarter] ?? collect(); @endphp

                <x-ui.card wire:key="quarter-{{ $quarter }}" :padding="false">
                    <x-slot:title>Q{{ $quarter }} {{ $year }}</x-slot:title>

                    <x-slot:actions>
                        @can('create', App\Models\TimeGridEntry::class)
                            <x-ui.button size="sm" wire:click="startAdding({{ $quarter }})">Add</x-ui.button>
                        @endcan
                    </x-slot:actions>

                    <div class="divide-y divide-line">
                        @forelse ($entries as $entry)
                            <div class="px-4 py-2.5" wire:key="entry-{{ $entry->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-medium text-ink-2">{{ $entry->activity }}</p>

                                    @can('update', $entry)
                                        <button type="button" wire:click="startEditing({{ $entry->id }})"
                                                class="shrink-0 text-xs text-ink-3 hover:text-gold">Edit</button>
                                    @endcan
                                </div>

                                <dl class="mt-1 flex gap-4 text-xs">
                                    <div>
                                        <dt class="text-ink-3">Planned</dt>
                                        <dd class="font-medium tabular-nums text-ink-2">
                                            {{ $entry->planned_hours !== null ? rtrim(rtrim((string) $entry->planned_hours, '0'), '.').' h' : '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-ink-3">Actual</dt>
                                        <dd class="font-medium tabular-nums text-ink-2">
                                            {{ $entry->actual_hours !== null ? rtrim(rtrim((string) $entry->actual_hours, '0'), '.').' h' : '—' }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-xs text-ink-3">Nothing allocated.</p>
                        @endforelse
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-ink-3">
            Planned and actual hours are shown side by side. No adherence rating or percentage is
            computed — none has been defined, and a variance the reader can see is more useful than a
            score nobody agreed.
        </p>
    @endif

    <x-ui.modal :show="$adding" :title="$editingId ? 'Amend entry' : 'Add to the grid'" close="$set('adding', false)">
        <form wire:submit="save" class="space-y-4">
            <x-ui.field label="Quarter" required :error="$errors->first('quarter')">
                <x-ui.select wire:model="quarter" :disabled="$editingId !== null">
                    @foreach ($quarters as $q)
                        <option value="{{ $q }}">Q{{ $q }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Activity" required
                        hint="One row per activity per quarter — the database enforces that."
                        :error="$errors->first('activity')">
                <x-ui.input wire:model="activity" :disabled="$editingId !== null" />
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Planned hours" :error="$errors->first('plannedHours')">
                    <x-ui.input type="number" step="0.25" min="0" wire:model="plannedHours" />
                </x-ui.field>

                <x-ui.field label="Actual hours" hint="Filled in after the quarter has run."
                            :error="$errors->first('actualHours')">
                    <x-ui.input type="number" step="0.25" min="0" wire:model="actualHours" />
                </x-ui.field>
            </div>

            @if ($editingId)
                <x-ui.alert tone="info">
                    Amendments are audited: the planned figure this quarter was reviewed against survives
                    being revised.
                </x-ui.alert>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('adding', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">{{ $editingId ? 'Amend' : 'Record' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
