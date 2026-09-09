<x-ui.workspace :customer="$customer" :tabs="$tabs" current="documents"
                subtitle="Files attached to this business. Stored privately and served through the application.">
    <x-slot:actions>
        @can('create', App\Models\Document::class)
            <x-ui.button size="sm" variant="primary" wire:click="startUpload">Upload document</x-ui.button>
        @endcan
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.table :headings="['File', 'Type', 'Size', 'Visibility', 'Uploaded', '>Actions']">
        @forelse ($documents as $document)
            <tr wire:key="document-{{ $document->id }}" class="hover:bg-slate-50">
                <x-ui.td class="font-medium">{{ $document->original_name }}</x-ui.td>
                <x-ui.td muted><span class="font-mono text-xs">{{ $document->mime_type }}</span></x-ui.td>
                <x-ui.td muted class="tabular-nums">
                    {{ number_format($document->size_bytes / 1024, 1) }} KB
                </x-ui.td>
                <x-ui.td>
                    <x-ui.badge :tone="$document->is_internal ? 'neutral' : 'info'">
                        {{ $document->is_internal ? 'Internal' : 'Shared' }}
                    </x-ui.badge>
                </x-ui.td>
                <x-ui.td muted>
                    {{ $document->created_at?->format('d M Y') }}
                    @if ($document->wasWrittenExternally())
                        <x-ui.badge tone="info" class="ml-1">Via link</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td align="right">
                    <div class="flex justify-end gap-1.5">
                        @can('view', $document)
                            <x-ui.button size="sm" wire:click="download({{ $document->id }})">Download</x-ui.button>
                        @endcan

                        @can('archive', $document)
                            <x-ui.button size="sm" variant="ghost"
                                         wire:click="archive({{ $document->id }})"
                                         wire:confirm="Archive this document?">Archive</x-ui.button>
                        @endcan
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="6" title="No documents"
                            description="Files uploaded here are private and are only served back through the application." />
        @endforelse
    </x-ui.table>

    <x-ui.modal :show="$uploading" title="Upload a document" close="$set('uploading', false)">
        <form wire:submit="upload" class="space-y-4">
            <x-ui.field label="File" required hint="Up to 20 MB." :error="$errors->first('file')">
                <input type="file" wire:model="file"
                       class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:text-white hover:file:bg-slate-700">
            </x-ui.field>

            <x-ui.loading target="file" label="Uploading…" />

            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="internal"
                       class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                <span>
                    Internal only
                    <span class="block text-xs text-slate-500">
                        Left on by default — an over-restricted file is a better mistake than a leaked one.
                    </span>
                </span>
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('uploading', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Upload</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
