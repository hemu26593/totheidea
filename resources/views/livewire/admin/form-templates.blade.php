<div>
    <x-ui.page-header title="Form templates" subtitle="Authoring instruments and their versions.">
        <x-slot:actions>
            @can('create', App\Models\FormTemplate::class)
                <x-ui.button variant="primary" wire:click="startTemplate">New template</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="grid gap-5 lg:grid-cols-4">
        <x-ui.card title="Templates" class="lg:col-span-1" :padding="false">
            <div class="divide-y divide-line">
                @forelse ($templates as $item)
                    <a href="{{ route('admin.form-templates', ['templateId' => $item->id]) }}"
                       wire:key="template-{{ $item->id }}"
                       class="block px-4 py-2.5 transition hover:bg-elevated {{ $template?->id === $item->id ? 'bg-raised' : '' }}">
                        <p class="text-sm font-medium text-ink">{{ $item->name }}</p>
                        <p class="text-xs text-ink-3">
                            <span class="font-mono">{{ $item->key }}</span>
                            · {{ $item->versions_count }} version(s)
                            @if ($item->is_scored) · scored @endif
                        </p>
                    </a>
                @empty
                    <p class="px-4 py-6 text-center text-xs text-ink-3">No templates yet.</p>
                @endforelse
            </div>
        </x-ui.card>

        <div class="space-y-5 lg:col-span-3">
            @if (! $template)
                <x-ui.card>
                    <x-ui.empty title="Choose a template"
                                description="Or create one. A template holds versions; a submission binds to a version, never to the template." />
                </x-ui.card>
            @else
                <x-ui.card :title="$template->name" :subtitle="'Key: '.$template->key" :padding="false">
                    <x-slot:actions>
                        @can('create', App\Models\FormVersion::class)
                            <x-ui.button size="sm" wire:click="startVersion">Start new version</x-ui.button>
                        @endcan
                    </x-slot:actions>

                    <x-ui.table :headings="['Version', 'Status', 'Published', 'Questions', '>']"
                                class="rounded-none shadow-none ring-0">
                        @forelse ($versions as $item)
                            <tr wire:key="version-{{ $item->id }}">
                                <x-ui.td class="font-medium tabular-nums">v{{ $item->version_number }}</x-ui.td>
                                <x-ui.td><x-ui.status-badge :status="$item->status" /></x-ui.td>
                                <x-ui.td muted>{{ $item->published_at?->format('d M Y') ?? '—' }}</x-ui.td>
                                <x-ui.td muted class="tabular-nums">{{ $item->questions()->count() }}</x-ui.td>
                                <x-ui.td align="right">
                                    <x-ui.button size="sm"
                                                 :href="route('admin.form-templates', ['templateId' => $template->id, 'versionId' => $item->id])">
                                        {{ $item->isDraft() ? 'Edit' : 'View' }}
                                    </x-ui.button>
                                </x-ui.td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="5" title="No versions"
                                            description="Start a draft version, add questions, then publish it." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                @if ($version)
                    <x-ui.card :title="'Version '.$version->version_number">
                        <x-slot:actions>
                            <x-ui.status-badge :status="$version->status" />

                            @if ($version->isDraft())
                                @can('update', $version)
                                    <x-ui.button size="sm" wire:click="startSection">Add section</x-ui.button>
                                @endcan
                                @can('publish', $version)
                                    <x-ui.button size="sm" variant="primary" wire:click="publish"
                                                 wire:confirm="Publish this version? It becomes immutable and answerable, and supersedes the current published version.">
                                        Publish
                                    </x-ui.button>
                                @endcan
                            @endif
                        </x-slot:actions>

                        @unless ($version->isDraft())
                            <x-ui.alert tone="info" class="mb-4">
                                This version is {{ $version->status }} and cannot be edited. A change means a
                                new version — every submission already bound to this one keeps its meaning.
                            </x-ui.alert>
                        @endunless

                        <div class="space-y-4">
                            @forelse ($sections as $section)
                                <div class="rounded-md ring-1 ring-line" wire:key="section-{{ $section->id }}">
                                    <div class="flex items-center justify-between border-b border-line px-3 py-2">
                                        <p class="text-sm font-medium">{{ $section->title }}</p>

                                        @if ($version->isDraft())
                                            @can('update', $version)
                                                <x-ui.button size="sm"
                                                             wire:click="startQuestion({{ $section->id }})">Add question</x-ui.button>
                                            @endcan
                                        @endif
                                    </div>

                                    <ul class="divide-y divide-line">
                                        @forelse ($section->questions as $question)
                                            <li class="flex items-start justify-between gap-3 px-3 py-2">
                                                <span class="text-sm text-ink-2">
                                                    {{ $question->label }}
                                                    @if ($question->is_required)
                                                        <span class="text-danger">*</span>
                                                    @endif
                                                </span>
                                                <x-ui.badge>{{ $question->type->value }}</x-ui.badge>
                                            </li>
                                        @empty
                                            <li class="px-3 py-3 text-xs text-ink-3">No questions in this section.</li>
                                        @endforelse
                                    </ul>
                                </div>
                            @empty
                                <x-ui.empty title="No sections"
                                            description="A version needs at least one question before it can be published." />
                            @endforelse
                        </div>

                        <p class="mt-4 border-t border-line pt-3 text-xs text-ink-3">
                            Choice questions get the Average / Good / Better / Best set with no numeric score —
                            that mapping is an open client decision and is not invented here.
                        </p>
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>

    <x-ui.modal :show="$creatingTemplate" title="New form template" close="$set('creatingTemplate', false)">
        <form wire:submit="createTemplate" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Name" required :error="$errors->first('name')">
                    <x-ui.input wire:model="name" />
                </x-ui.field>

                <x-ui.field label="Key" required :error="$errors->first('key')">
                    <x-ui.input wire:model="key" class="font-mono" />
                </x-ui.field>
            </div>

            <label class="flex items-start gap-2 text-sm text-ink-2">
                <input type="checkbox" wire:model="isScored"
                       class="mt-0.5 rounded border-line text-ink focus:ring-gold">
                <span>
                    Scored instrument
                    <span class="block text-xs text-ink-3">
                        Scoring is an explicit act with a recorded scheme version, never a side effect of
                        submitting.
                    </span>
                </span>
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('creatingTemplate', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Create</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$addingSection" title="Add a section" close="$set('addingSection', false)">
        <form wire:submit="addSection" class="space-y-4">
            <x-ui.field label="Section title" required :error="$errors->first('sectionTitle')">
                <x-ui.input wire:model="sectionTitle" />
            </x-ui.field>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" wire:click="$set('addingSection', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$addingQuestionTo !== null" title="Add a question" close="$set('addingQuestionTo', null)">
        <form wire:submit="addQuestion" class="space-y-4">
            <x-ui.field label="Label" required :error="$errors->first('questionLabel')">
                <x-ui.textarea wire:model="questionLabel" rows="2" />
            </x-ui.field>

            <x-ui.field label="Type" required :error="$errors->first('questionType')">
                <x-ui.select wire:model="questionType">
                    @foreach ($questionTypes as $type)
                        <option value="{{ $type->value }}">{{ Str::headline($type->value) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <label class="flex items-center gap-2 text-sm text-ink-2">
                <input type="checkbox" wire:model="questionRequired"
                       class="rounded border-line text-ink focus:ring-gold">
                Required
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('addingQuestionTo', null)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add question</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
