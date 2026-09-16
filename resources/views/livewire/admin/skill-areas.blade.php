<div>
    <x-ui.page-header title="Skill areas"
                      subtitle="The dimensions a scored instrument reports against.">
        <x-slot:actions>
            @can('create', App\Models\FormTemplate::class)
                <x-ui.button variant="primary" wire:click="startCreate">New skill area</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.table :headings="['#', 'Skill area', 'Key', 'Description']">
        @forelse ($skillAreas as $area)
            <tr wire:key="skill-area-{{ $area->id }}">
                <x-ui.td muted class="tabular-nums">{{ $area->position }}</x-ui.td>
                <x-ui.td class="font-medium">{{ $area->name }}</x-ui.td>
                <x-ui.td muted><span class="font-mono text-xs">{{ $area->key }}</span></x-ui.td>
                <x-ui.td muted>{{ $area->description ?? '—' }}</x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="4" title="No skill areas"
                            description="Scored questions attach to a skill area so a submission can be reported per dimension." />
        @endforelse
    </x-ui.table>

    <p class="mt-4 max-w-2xl text-xs text-ink-3">
        A skill area carries no thresholds and no bands. What counts as weak or strong within one has not
        been defined, so no field anticipates the answer.
    </p>

    <x-ui.modal :show="$creating" title="New skill area" close="$set('creating', false)">
        <form wire:submit="create" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Name" required :error="$errors->first('name')">
                    <x-ui.input wire:model="name" />
                </x-ui.field>

                <x-ui.field label="Key" required :error="$errors->first('key')">
                    <x-ui.input wire:model="key" class="font-mono" />
                </x-ui.field>
            </div>

            <x-ui.field label="Description" :error="$errors->first('description')">
                <x-ui.textarea wire:model="description" rows="3" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('creating', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Create</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
