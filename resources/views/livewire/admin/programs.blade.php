<div>
    <x-ui.page-header title="Programmes" subtitle="The container a batch and a curriculum belong to.">
        <x-slot:actions>
            @can('create', App\Models\Batch::class)
                <x-ui.button variant="primary" wire:click="startCreate">New programme</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.table :headings="['Programme', 'Code', 'Sessions', 'Batches', 'Curriculum', '>']">
        @forelse ($programs as $program)
            <tr wire:key="program-{{ $program->id }}" class="hover:bg-elevated">
                <x-ui.td class="font-medium">{{ $program->name }}</x-ui.td>
                <x-ui.td muted><span class="font-mono text-xs">{{ $program->code }}</span></x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $program->session_count }}</x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $program->batches_count }}</x-ui.td>
                <x-ui.td>
                    @if ($program->session_templates_count >= $program->session_count)
                        <x-ui.badge tone="success">Complete</x-ui.badge>
                    @else
                        <x-ui.badge tone="warning">
                            {{ $program->session_templates_count }} of {{ $program->session_count }}
                        </x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td align="right">
                    <x-ui.button size="sm" :href="route('admin.curriculum', ['programId' => $program->id])">
                        Curriculum
                    </x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="6" title="No programmes"
                            description="Everything else hangs off a programme: batches, curricula and enrolments." />
        @endforelse
    </x-ui.table>

    <x-ui.modal :show="$creating" title="New programme" close="$set('creating', false)">
        <form wire:submit="create" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Name" required :error="$errors->first('name')">
                    <x-ui.input wire:model="name" />
                </x-ui.field>

                <x-ui.field label="Code" required :error="$errors->first('code')">
                    <x-ui.input wire:model="code" class="font-mono" />
                </x-ui.field>
            </div>

            <x-ui.field label="Description" :error="$errors->first('description')">
                <x-ui.textarea wire:model="description" rows="3" />
            </x-ui.field>

            <x-ui.field label="Sessions" required hint="The delivered programme is six sessions."
                        :error="$errors->first('sessionCount')">
                <x-ui.input type="number" wire:model="sessionCount" min="1" max="6" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('creating', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Create</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
