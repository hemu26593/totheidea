<div>
    <x-ui.page-header :title="$assignment->title"
                      :subtitle="$assignment->sessionInstance?->sessionTemplate?->title"
                      :breadcrumbs="[
                          'Assignments' => route('assignments.index'),
                          $assignment->title => null,
                      ]">
        <x-slot:actions>
            <x-ui.status-badge :status="$assignment->status" />

            @if ($assignment->status === App\Models\AssignmentInstance::STATUS_DRAFT)
                @can('release', $assignment)
                    <x-ui.button size="sm" variant="primary" wire:click="release">Release</x-ui.button>
                @endcan
            @elseif ($assignment->status === App\Models\AssignmentInstance::STATUS_RELEASED)
                @can('close', $assignment)
                    <x-ui.button size="sm" wire:click="close"
                                 wire:confirm="Close this assignment? No further submissions will be accepted.">
                        Close
                    </x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mb-5 grid gap-5 lg:grid-cols-3">
        <x-ui.card title="Assignment">
            <dl class="divide-y divide-line">
                <x-ui.definition term="Batch">{{ $assignment->sessionInstance?->batch?->name }}</x-ui.definition>
                <x-ui.definition term="Session">
                    Session {{ $assignment->sessionInstance?->sessionTemplate?->sequence }} —
                    {{ $assignment->sessionInstance?->sessionTemplate?->title }}
                </x-ui.definition>
                <x-ui.definition term="Due">
                    {{ $assignment->due_at?->format('d M Y H:i') ?? '—' }}
                </x-ui.definition>
                <x-ui.definition term="Released">
                    {{ $assignment->released_at?->format('d M Y') ?? 'Not yet' }}
                </x-ui.definition>
                <x-ui.definition term="Attachment required">
                    {{ $assignment->requires_attachment ? 'Yes' : 'No' }}
                </x-ui.definition>
            </dl>
        </x-ui.card>

        <x-ui.card title="Instructions" class="lg:col-span-2">
            @if ($assignment->instructions)
                <p class="whitespace-pre-line text-sm text-ink-2">{{ $assignment->instructions }}</p>
            @else
                <x-ui.empty title="No instructions recorded" />
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Submissions"
               subtitle="One row per participant on this batch's roster."
               :padding="false">
        <x-ui.table :headings="['Participant', 'Status', 'Attempt', 'Submitted', 'Last review', '>']" class="rounded-none shadow-none ring-0">
            @forelse ($roster as $enrollment)
                @php
                    $submission = $submissions[$enrollment->id] ?? null;
                    $lastReview = $submission?->reviews->sortByDesc('reviewed_at')->first();
                @endphp

                <tr wire:key="submission-row-{{ $enrollment->id }}">
                    <x-ui.td class="font-medium">{{ $enrollment->customer?->name }}</x-ui.td>
                    <x-ui.td>
                        @if ($submission)
                            <x-ui.status-badge :status="$submission->status" />
                        @else
                            <x-ui.badge>Not started</x-ui.badge>
                        @endif
                    </x-ui.td>
                    <x-ui.td muted class="tabular-nums">{{ $submission?->attempt_number ?? '—' }}</x-ui.td>
                    <x-ui.td muted>{{ $submission?->submitted_at?->format('d M Y H:i') ?? '—' }}</x-ui.td>
                    <x-ui.td muted>
                        @if ($lastReview)
                            <x-ui.status-badge :status="$lastReview->decision" />
                            <span class="ml-1 text-xs">{{ $lastReview->reviewedBy?->name }}</span>
                        @else
                            —
                        @endif
                    </x-ui.td>
                    <x-ui.td align="right">
                        @if ($submission?->isSubmitted())
                            @can('review', $submission)
                                <x-ui.button size="sm" variant="primary"
                                             wire:click="startReview({{ $submission->id }})">Review</x-ui.button>
                            @endcan
                        @elseif ($submission)
                            <span class="text-xs text-ink-3">Nothing to review</span>
                        @else
                            <span class="text-xs text-ink-3">No submission</span>
                        @endif
                    </x-ui.td>
                </tr>

                @if ($submission && $submission->reviews->isNotEmpty())
                    <tr class="bg-elevated/60" wire:key="history-{{ $enrollment->id }}">
                        <td colspan="6" class="px-4 py-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-ink-3">
                                Review history — appended, never edited
                            </p>

                            <ul class="space-y-1">
                                @foreach ($submission->reviews->sortByDesc('reviewed_at') as $review)
                                    <li class="flex flex-wrap items-baseline gap-2 text-xs text-ink-2">
                                        <x-ui.status-badge :status="$review->decision" />
                                        <span>{{ $review->reviewedBy?->name }}</span>
                                        <span class="text-ink-3">{{ $review->reviewed_at?->format('d M Y H:i') }}</span>
                                        @if ($review->remark)
                                            <span class="text-ink-2">— {{ $review->remark }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty-row :colspan="6" title="Nobody enrolled in this batch" />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.modal :show="$reviewingSubmissionId !== null" title="Review submission"
                close="$set('reviewingSubmissionId', null)">
        @if ($reviewing)
            <p class="mb-3 text-sm text-ink-2">
                <span class="font-medium text-ink">{{ $reviewing->enrollment?->customer?->name }}</span>,
                attempt {{ $reviewing->attempt_number }}.
            </p>

            @if ($reviewing->body)
                <div class="mb-4 max-h-52 overflow-y-auto rounded-md bg-raised p-3 text-sm whitespace-pre-line ring-1 ring-inset ring-line">
                    {{ $reviewing->body }}
                </div>
            @endif
        @endif

        <x-ui.field label="Remark" hint="Required when returning for rework." :error="$errors->first('reviewRemark')">
            <x-ui.textarea wire:model="reviewRemark" rows="3" />
        </x-ui.field>

        <p class="mt-3 text-xs text-ink-3">
            A review is appended to the history. Returning for rework lets the participant submit again;
            the earlier attempt stays on the record.
        </p>

        <x-slot:footer>
            <x-ui.button wire:click="$set('reviewingSubmissionId', null)">Cancel</x-ui.button>
            <x-ui.button wire:click="returnForRework">Return for rework</x-ui.button>
            <x-ui.button variant="primary" wire:click="accept">Accept</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
