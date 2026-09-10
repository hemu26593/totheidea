<div>
    <x-ui.page-header title="Batches" subtitle="A programme run. Enrolments, sessions and assignments hang off a batch.">
        <x-slot:actions>
            @can('create', App\Models\Batch::class)
                <x-ui.button variant="primary" wire:click="startCreate">New batch</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.search placeholder="Search batch name or code" />

        <x-ui.select wire:model.live="programId" class="w-auto">
            <option value="">All programmes</option>
            @foreach ($programs as $program)
                <option value="{{ $program->id }}">{{ $program->name }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="search,programId" />
    </x-ui.filters>

    <x-ui.table :headings="['Batch', 'Programme', 'Starts', 'Status', 'Enrolled', 'Capacity', '>']">
        @forelse ($batches as $batch)
            <tr wire:key="batch-{{ $batch->id }}" class="hover:bg-slate-50">
                <x-ui.td>
                    <span class="font-medium">{{ $batch->name }}</span>
                    <span class="ml-1.5 font-mono text-xs text-slate-500">{{ $batch->code }}</span>
                </x-ui.td>
                <x-ui.td muted>{{ $batch->program?->name }}</x-ui.td>
                <x-ui.td muted>{{ $batch->starts_on?->format('d M Y') }}</x-ui.td>
                <x-ui.td><x-ui.status-badge :status="$batch->status" /></x-ui.td>
                <x-ui.td class="tabular-nums">{{ $batch->active_enrollments_count }}</x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $batch->capacity ?? 'Uncapped' }}</x-ui.td>
                <x-ui.td align="right">
                    <x-ui.button size="sm" :href="route('batches.show', $batch)">Open</x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="7" title="No batches"
                            description="A batch is a programme run. Create one before enrolling anybody." />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $batches->links() }}</div>

    <x-ui.modal :show="$creating" title="New batch" close="$set('creating', false)">
        <form wire:submit="create" class="space-y-4">
            <x-ui.field label="Programme" required :error="$errors->first('newProgramId')">
                <x-ui.select wire:model="newProgramId">
                    <option value="">Choose a programme…</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program->id }}">{{ $program->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Name" required :error="$errors->first('name')">
                    <x-ui.input wire:model="name" />
                </x-ui.field>

                <x-ui.field label="Code" required :error="$errors->first('code')">
                    <x-ui.input wire:model="code" class="font-mono" />
                </x-ui.field>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.field label="Starts on" required :error="$errors->first('startsOn')">
                    <x-ui.input type="date" wire:model="startsOn" />
                </x-ui.field>

                <x-ui.field label="Ends on" :error="$errors->first('endsOn')">
                    <x-ui.input type="date" wire:model="endsOn" />
                </x-ui.field>

                <x-ui.field label="Capacity" hint="Blank = uncapped" :error="$errors->first('capacity')">
                    <x-ui.input type="number" wire:model="capacity" min="1" />
                </x-ui.field>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('creating', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Create batch</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
