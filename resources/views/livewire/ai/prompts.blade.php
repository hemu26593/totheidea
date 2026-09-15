<div>
    <x-ui.page-header title="AI prompt versions"
                      subtitle="What the model is told to do. A published prompt is immutable — a change is a new version.">
        <x-slot:actions>
            @can('create', App\Models\AiPromptVersion::class)
                <x-ui.button variant="primary" wire:click="startDraft">New draft</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="space-y-5">
        @forelse ($prompts as $key => $versions)
            <x-ui.card wire:key="prompt-{{ $key }}" :padding="false">
                <x-slot:title><span class="font-mono">{{ $key }}</span></x-slot:title>
                <x-slot:subtitle>{{ $versions->count() }} version(s)</x-slot:subtitle>

                <x-ui.table :headings="['Version', 'Status', 'Model', 'Generations', 'Author', '>']"
                            class="rounded-none shadow-none ring-0">
                    @foreach ($versions as $prompt)
                        <tr wire:key="prompt-version-{{ $prompt->id }}">
                            <x-ui.td class="font-medium tabular-nums">v{{ $prompt->version_number }}</x-ui.td>
                            <x-ui.td><x-ui.status-badge :status="$prompt->status" /></x-ui.td>
                            <x-ui.td muted>{{ $prompt->model_identifier ?? 'Configured default' }}</x-ui.td>
                            <x-ui.td muted class="tabular-nums">{{ $prompt->generations_count }}</x-ui.td>
                            <x-ui.td muted>{{ $prompt->createdBy?->name }}</x-ui.td>
                            <x-ui.td align="right">
                                @if ($prompt->isDraft())
                                    @can('publish', $prompt)
                                        <x-ui.button size="sm" variant="primary"
                                                     wire:click="publish({{ $prompt->id }})"
                                                     wire:confirm="Publish this prompt version? It becomes immutable, and every later generation will name it.">
                                            Publish
                                        </x-ui.button>
                                    @endcan
                                @else
                                    <span class="text-xs text-ink-3">Immutable</span>
                                @endif
                            </x-ui.td>
                        </tr>

                        <tr class="bg-elevated/60" wire:key="prompt-body-{{ $prompt->id }}">
                            <td colspan="6" class="px-4 py-2">
                                <pre class="max-h-40 overflow-auto whitespace-pre-wrap text-xs leading-relaxed text-ink-2">{{ $prompt->template }}</pre>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty title="No prompt versions"
                            description="Generation is refused until a prompt has been published — never run against an unreviewed draft." />
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.modal :show="$drafting" title="New prompt version" close="$set('drafting', false)">
        <form wire:submit="saveDraft" class="space-y-4">
            <x-ui.field label="Key" required
                        hint="e.g. form_draft, diagnostic_narrative. A new version of an existing key continues its history."
                        :error="$errors->first('key')">
                <x-ui.input wire:model="key" class="font-mono" />
            </x-ui.field>

            <x-ui.field label="Template" required
                        hint="Placeholders only, written in double braces. Never a business's own text — customer context is substituted at call time."
                        :error="$errors->first('template')">
                <x-ui.textarea wire:model="template" rows="8" class="font-mono text-xs" />
            </x-ui.field>

            <x-ui.field label="Model identifier" hint="Optional. Recorded for provenance; blank uses the configured default."
                        :error="$errors->first('modelIdentifier')">
                <x-ui.input wire:model="modelIdentifier" class="font-mono" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('drafting', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Create draft</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
