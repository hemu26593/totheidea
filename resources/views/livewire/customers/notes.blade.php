<x-ui.workspace :customer="$customer" :tabs="$tabs" current="notes"
                subtitle="Consultant notes. Internal by default, and never authored by an external link.">
    <x-slot:actions>
        @can('create', App\Models\Note::class)
            <x-ui.button size="sm" variant="primary" wire:click="startNote">Add note</x-ui.button>
        @endcan
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="space-y-3">
        @forelse ($notes as $note)
            <x-ui.card wire:key="note-{{ $note->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="whitespace-pre-line text-sm text-slate-800">{{ $note->body }}</p>

                        <p class="mt-2 text-xs text-slate-500">
                            {{ $note->author?->name }} · {{ $note->created_at?->format('d M Y H:i') }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <x-ui.badge :tone="$note->is_internal ? 'neutral' : 'info'">
                            {{ $note->is_internal ? 'Internal' : 'Shared' }}
                        </x-ui.badge>

                        @can('update', $note)
                            <x-ui.button size="sm" wire:click="startEditing({{ $note->id }})">Edit</x-ui.button>
                        @endcan

                        @can('setVisibility', $note)
                            <x-ui.button size="sm" wire:click="toggleVisibility({{ $note->id }})">
                                {{ $note->is_internal ? 'Share' : 'Make internal' }}
                            </x-ui.button>
                        @endcan

                        @can('archive', $note)
                            <x-ui.button size="sm" variant="ghost"
                                         wire:click="archive({{ $note->id }})"
                                         wire:confirm="Archive this note?">Archive</x-ui.button>
                        @endcan
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty title="No notes"
                            description="Internal notes are excluded from the query for anyone without permission to read them — not merely hidden." />
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.modal :show="$writing" :title="$editingId ? 'Edit note' : 'Add a note'" close="$set('writing', false)">
        <form wire:submit="save" class="space-y-4">
            <x-ui.field label="Note" required :error="$errors->first('body')">
                <x-ui.textarea wire:model="body" rows="6" />
            </x-ui.field>

            @unless ($editingId)
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="internal"
                           class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                    <span>
                        Internal only
                        <span class="block text-xs text-slate-500">
                            The default. Participants never see an internal note.
                        </span>
                    </span>
                </label>
            @endunless

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('writing', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">{{ $editingId ? 'Save' : 'Add note' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
