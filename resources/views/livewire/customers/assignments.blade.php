<x-ui.workspace :customer="$customer" :tabs="$tabs" current="assignments"
                subtitle="Released assignments and this business's submissions.">
    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.table :headings="['Assignment', 'Session', 'Due', 'Status', 'Attempt', 'Last review', '>Actions']">
        @forelse ($instances as $instance)
            @php
                $submission = $submissions[$instance->id] ?? null;
                $enrollment = $enrollments[$instance->sessionInstance?->batch_id] ?? null;
                $overdue = $instance->due_at?->isPast() && ! $submission?->isSubmitted() && ! $submission?->isAccepted();
                $lastReview = $submission?->reviews->sortByDesc('reviewed_at')->first();
            @endphp

            <tr wire:key="instance-{{ $instance->id }}" class="hover:bg-slate-50">
                <x-ui.td class="font-medium">{{ $instance->title }}</x-ui.td>
                <x-ui.td muted>Session {{ $instance->sessionInstance?->sessionTemplate?->sequence }}</x-ui.td>
                <x-ui.td>
                    <span class="{{ $overdue ? 'font-medium text-rose-700' : 'text-slate-600' }}">
                        {{ $instance->due_at?->format('d M Y') }}
                    </span>
                </x-ui.td>
                <x-ui.td>
                    @if ($submission)
                        <x-ui.status-badge :status="$submission->status" />
                    @else
                        <x-ui.badge>Not started</x-ui.badge>
                    @endif
                    @if ($overdue)
                        <x-ui.badge tone="danger" class="ml-1">Overdue</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $submission?->attempt_number ?? '—' }}</x-ui.td>
                <x-ui.td muted>
                    @if ($lastReview)
                        <x-ui.status-badge :status="$lastReview->decision" />
                        @if ($lastReview->remark)
                            <p class="mt-0.5 text-xs">{{ Str::limit($lastReview->remark, 60) }}</p>
                        @endif
                    @else
                        —
                    @endif
                </x-ui.td>
                <x-ui.td align="right">
                    <div class="flex justify-end gap-1.5">
                        @if ($enrollment && ! $submission?->isAccepted())
                            @can('create', App\Models\AssignmentSubmission::class)
                                <x-ui.button size="sm"
                                             wire:click="startSubmission({{ $instance->id }}, {{ $enrollment->id }})">
                                    Record submission
                                </x-ui.button>
                            @endcan
                        @endif

                        @can('assignments.view')
                            <x-ui.button size="sm" :href="route('assignments.show', $instance)">Open</x-ui.button>
                        @endcan
                    </div>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="7" title="No assignments released"
                            description="Staged assignments are not shown — a draft has not been given to the batch yet." />
        @endforelse
    </x-ui.table>

    <x-ui.modal :show="$submittingInstanceId !== null" title="Record a submission"
                close="$set('submittingInstanceId', null)">
        <form wire:submit="submit" class="space-y-4">
            <p class="text-sm text-slate-600">
                Participants have no account, so a submission taken on paper or by phone is recorded here.
                It is stored as a staff-entered submission, and the record says so.
            </p>

            <x-ui.field label="Submission" required :error="$errors->first('body')">
                <x-ui.textarea wire:model="body" rows="6" />
            </x-ui.field>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" wire:click="$set('submittingInstanceId', null)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Record</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
