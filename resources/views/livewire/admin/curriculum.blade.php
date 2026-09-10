<div>
    <x-ui.page-header title="Curriculum"
                      subtitle="Sessions 1–6, the forms each carries, and the assignments each sets.">
        <x-slot:actions>
            @can('create', App\Models\SessionTemplate::class)
                <x-ui.button variant="primary" wire:click="startSession" :disabled="! $program">
                    Define session
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.select wire:model.live="programId" class="w-auto">
            <option value="">Choose a programme…</option>
            @foreach ($programs as $p)
                <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code }})</option>
            @endforeach
        </x-ui.select>

        @if ($program)
            @if ($curriculumComplete)
                <x-ui.badge tone="success">Curriculum complete</x-ui.badge>
            @else
                <x-ui.badge tone="warning">
                    {{ $sessions->count() }} of {{ $program->session_count }} sessions defined
                </x-ui.badge>
            @endif
        @endif

        <x-ui.loading target="programId" />
    </x-ui.filters>

    @if (! $program)
        <x-ui.card>
            <x-ui.empty title="Choose a programme"
                        description="A curriculum belongs to a programme. Pick one to see its sessions." />
        </x-ui.card>
    @else
        <div class="space-y-4">
            @forelse ($sessions as $session)
                <x-ui.card wire:key="session-template-{{ $session->id }}">
                    <x-slot:title>Session {{ $session->sequence }} — {{ $session->title }}</x-slot:title>
                    <x-slot:subtitle>{{ $session->theme }}</x-slot:subtitle>

                    <x-slot:actions>
                        @can('create', App\Models\SessionTemplate::class)
                            <x-ui.button size="sm" wire:click="startFormAttachment({{ $session->id }})">
                                Attach form
                            </x-ui.button>
                        @endcan

                        @can('create', App\Models\AssignmentTemplate::class)
                            <x-ui.button size="sm" wire:click="startAssignment({{ $session->id }})">
                                Define assignment
                            </x-ui.button>
                        @endcan
                    </x-slot:actions>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-500">Forms</p>

                            @forelse ($session->templateForms as $link)
                                <div class="flex items-center justify-between border-b border-slate-100 py-1.5 last:border-0">
                                    <span class="text-sm">{{ $link->formTemplate?->name }}</span>
                                    @if ($link->is_required)
                                        <x-ui.badge tone="warning">Required</x-ui.badge>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-slate-400">None attached.</p>
                            @endforelse
                        </div>

                        <div>
                            <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-500">Assignments</p>

                            @forelse ($session->assignmentTemplates as $assignment)
                                <div class="border-b border-slate-100 py-1.5 last:border-0">
                                    <p class="text-sm">{{ $assignment->title }}</p>
                                </div>
                            @empty
                                <p class="text-xs text-slate-400">None defined.</p>
                            @endforelse
                        </div>
                    </div>

                    @if ($session->objectives)
                        <div class="mt-4 border-t border-slate-100 pt-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Objectives</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $session->objectives }}</p>
                        </div>
                    @endif
                </x-ui.card>
            @empty
                <x-ui.card>
                    <x-ui.empty title="No sessions defined"
                                description="Define sessions 1 to 6. A batch cannot be scheduled against a curriculum that does not exist." />
                </x-ui.card>
            @endforelse
        </div>
    @endif

    <x-ui.modal :show="$definingSession" title="Define a session" close="$set('definingSession', false)">
        <form wire:submit="defineSession" class="space-y-4">
            <x-ui.field label="Sequence" required
                        hint="Sessions 1–6 are the delivered programme."
                        :error="$errors->first('sequence')">
                <x-ui.select wire:model="sequence">
                    @for ($i = 1; $i <= $maxSequence; $i++)
                        <option value="{{ $i }}">Session {{ $i }}</option>
                    @endfor
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Title" required :error="$errors->first('title')">
                <x-ui.input wire:model="title" />
            </x-ui.field>

            <x-ui.field label="Theme" :error="$errors->first('theme')">
                <x-ui.input wire:model="theme" />
            </x-ui.field>

            <x-ui.field label="Objectives" :error="$errors->first('objectives')">
                <x-ui.textarea wire:model="objectives" rows="4" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('definingSession', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Define</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$attachingToSessionId !== null" title="Attach a form"
                close="$set('attachingToSessionId', null)">
        <form wire:submit="attachForm" class="space-y-4">
            <x-ui.field label="Form template" required :error="$errors->first('formTemplateId')">
                <x-ui.select wire:model="formTemplateId">
                    <option value="">Choose a form…</option>
                    @foreach ($formTemplates as $form)
                        <option value="{{ $form->id }}">{{ $form->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="formRequired"
                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                Required at this session
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('attachingToSessionId', null)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Attach</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$definingAssignmentFor !== null" title="Define an assignment"
                close="$set('definingAssignmentFor', null)">
        <form wire:submit="defineAssignment" class="space-y-4">
            <x-ui.field label="Title" required :error="$errors->first('assignmentTitle')">
                <x-ui.input wire:model="assignmentTitle" />
            </x-ui.field>

            <x-ui.field label="Instructions" :error="$errors->first('assignmentInstructions')">
                <x-ui.textarea wire:model="assignmentInstructions" rows="5" />
            </x-ui.field>

            <p class="text-xs text-slate-500">
                A template carries no due date. Only a released instance has one, set when the assignment
                is actually staged at a session.
            </p>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('definingAssignmentFor', null)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Define</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
