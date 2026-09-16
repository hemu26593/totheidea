<div>
    <x-ui.page-header :title="'Session '.($session->sessionTemplate?->sequence ?? '').' — '.($session->sessionTemplate?->title ?? 'Session')"
                      :subtitle="$session->sessionTemplate?->theme"
                      :breadcrumbs="[
                          'Sessions' => route('sessions.index'),
                          $session->batch?->name => route('batches.show', $session->batch_id),
                          'Session '.($session->sessionTemplate?->sequence ?? '') => null,
                      ]">
        <x-slot:actions>
            <x-ui.status-badge :status="$session->status" />

            @if ($session->status === App\Models\SessionInstance::STATUS_SCHEDULED)
                @can('update', $session)
                    <x-ui.button size="sm" variant="primary" wire:click="begin">Start session</x-ui.button>
                @endcan
            @elseif ($session->status === App\Models\SessionInstance::STATUS_IN_PROGRESS)
                @can('complete', $session)
                    <x-ui.button size="sm" variant="primary" wire:click="completeSession"
                                 wire:confirm="Mark this session completed?">Complete session</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mb-5 grid gap-5 lg:grid-cols-3">
        <x-ui.card title="Session information">
            <dl class="divide-y divide-line">
                <x-ui.definition term="Batch">{{ $session->batch?->name }}</x-ui.definition>
                <x-ui.definition term="Programme">{{ $session->batch?->program?->name }}</x-ui.definition>
                <x-ui.definition term="Planned date">{{ $session->planned_date?->format('d M Y') }}</x-ui.definition>
                <x-ui.definition term="Actual date">{{ $session->actual_date?->format('d M Y') ?? 'Not held yet' }}</x-ui.definition>
                <x-ui.definition term="Venue">{{ $session->venue ?? '—' }}</x-ui.definition>
                <x-ui.definition term="Conducted by">{{ $session->conductedBy?->name ?? '—' }}</x-ui.definition>
            </dl>

            @if ($session->sessionTemplate?->objectives)
                <div class="mt-3 border-t border-line pt-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-3">Objectives</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-ink-2">{{ $session->sessionTemplate->objectives }}</p>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Attendance summary"
                   subtitle="Counts of what was recorded — not a percentage.">
            <div class="grid grid-cols-2 gap-3">
                @foreach ($statuses as $status)
                    <div class="rounded-md bg-raised px-3 py-2 ring-1 ring-inset ring-line">
                        <p class="text-xs text-ink-3">{{ Str::headline($status) }}</p>
                        <p class="text-xl font-semibold tabular-nums">{{ $statusCounts[$status] ?? 0 }}</p>
                    </div>
                @endforeach
            </div>

            <x-ui.alert tone="info" class="mt-4">
                No attendance percentage is shown. The weighting of <em>late</em> and <em>excused</em> is an
                open client decision, so this application has no authoritative formula and does not invent one.
            </x-ui.alert>
        </x-ui.card>

        <x-ui.card title="Session forms" subtitle="Instruments attached to this session in the curriculum.">
            @forelse ($sessionForms as $link)
                <div class="flex items-center justify-between border-b border-line py-2 last:border-0"
                     wire:key="form-link-{{ $link->id }}">
                    <div>
                        <p class="text-sm font-medium">{{ $link->formTemplate?->name }}</p>
                        <p class="text-xs text-ink-3">{{ $link->formTemplate?->key }}</p>
                    </div>

                    @if ($link->is_required)
                        <x-ui.badge tone="warning">Required</x-ui.badge>
                    @endif
                </div>
            @empty
                <x-ui.empty title="No forms attached"
                            description="Forms are attached to a session template in the curriculum." />
            @endforelse
        </x-ui.card>
    </div>

    <x-ui.card title="Attendance register"
               subtitle="Marking is refused until the session has actually been held."
               class="mb-5"
               :padding="false">
        <x-ui.table :headings="['Participant', 'Code', 'Current mark', '>Record']" class="rounded-none shadow-none ring-0">
            @forelse ($roster as $enrollment)
                @php $mark = $marks[$enrollment->id] ?? null; @endphp

                <tr wire:key="roster-{{ $enrollment->id }}">
                    <x-ui.td class="font-medium">{{ $enrollment->customer?->name }}</x-ui.td>
                    <x-ui.td muted><span class="font-mono text-xs">{{ $enrollment->customer?->code }}</span></x-ui.td>
                    <x-ui.td>
                        @if ($mark)
                            <x-ui.status-badge :status="$mark->status" />
                            @if ($mark->amended_at)
                                <span class="ml-1 text-xs text-ink-3">amended</span>
                            @endif
                        @else
                            <span class="text-ink-3">Not marked</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td align="right">
                        @can('create', App\Models\SessionAttendance::class)
                            @if ($session->isHeld())
                                <div class="flex flex-wrap justify-end gap-1">
                                    @foreach ($statuses as $status)
                                        <x-ui.button size="sm"
                                                     :variant="$mark?->status === $status ? 'primary' : 'secondary'"
                                                     wire:click="mark({{ $enrollment->id }}, '{{ $status }}')">
                                            {{ Str::headline($status) }}
                                        </x-ui.button>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-xs text-ink-3">Start the session first</span>
                            @endif
                        @endcan
                    </x-ui.td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" title="Nobody enrolled in this batch" />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Assignments" subtitle="Set at this session." :padding="false">
        <x-slot:actions>
            @can('create', App\Models\AssignmentInstance::class)
                <x-ui.button size="sm" wire:click="startAssignmentRelease">Stage assignment</x-ui.button>
            @endcan
        </x-slot:actions>

        <x-ui.table :headings="['Assignment', 'Due', 'Status', 'Submissions', '>']" class="rounded-none shadow-none ring-0">
            @forelse ($assignments as $assignment)
                <tr wire:key="assignment-{{ $assignment->id }}">
                    <x-ui.td class="font-medium">{{ $assignment->title }}</x-ui.td>
                    <x-ui.td muted>{{ $assignment->due_at?->format('d M Y') ?? '—' }}</x-ui.td>
                    <x-ui.td><x-ui.status-badge :status="$assignment->status" /></x-ui.td>
                    <x-ui.td muted class="tabular-nums">{{ $assignment->submissions_count }}</x-ui.td>
                    <x-ui.td align="right">
                        <div class="flex justify-end gap-1.5">
                            @if ($assignment->status === App\Models\AssignmentInstance::STATUS_DRAFT)
                                @can('release', $assignment)
                                    <x-ui.button size="sm" variant="primary"
                                                 wire:click="releaseAssignment({{ $assignment->id }})">Release</x-ui.button>
                                @endcan
                            @endif

                            <x-ui.button size="sm" :href="route('assignments.show', $assignment)">Open</x-ui.button>
                        </div>
                    </x-ui.td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" title="No assignments set"
                                description="Stage one from the curriculum, then release it when the session is done." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.modal :show="$releasingAssignment" title="Stage an assignment" close="$set('releasingAssignment', false)">
        <form wire:submit="stageAssignment" class="space-y-4">
            <x-ui.field label="Assignment" required :error="$errors->first('assignmentTemplateId')">
                <x-ui.select wire:model="assignmentTemplateId">
                    <option value="">Choose an assignment…</option>
                    @foreach ($assignmentTemplates as $template)
                        <option value="{{ $template->id }}">{{ $template->title }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($assignmentTemplates->isEmpty())
                <x-ui.alert tone="warning">
                    This session's curriculum defines no assignments.
                </x-ui.alert>
            @endif

            <x-ui.field label="Due date" required :error="$errors->first('assignmentDueAt')">
                <x-ui.input type="date" wire:model="assignmentDueAt" />
            </x-ui.field>

            <p class="text-xs text-ink-3">
                Staging creates a draft. Participants see nothing until it is released, and the
                overdue reminders only start counting from release.
            </p>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('releasingAssignment', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit" :disabled="$assignmentTemplates->isEmpty()">Stage</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
