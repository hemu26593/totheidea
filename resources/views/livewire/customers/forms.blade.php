<x-ui.workspace :customer="$customer" :tabs="$tabs" current="forms"
                subtitle="Intake and session instruments, rendered from their published versions.">
    <x-slot:actions>
        @can('create', App\Models\FormSubmission::class)
            <x-ui.button size="sm" variant="primary" wire:click="startSubmission">Start a form</x-ui.button>
        @endcan
    </x-slot:actions>

    {{-- Rendered here, not only by the layout's <x-ui.flash>: that sits outside
         the component root, and a Livewire update re-renders only the component,
         so a success confirmed mid-page would otherwise stay invisible until the
         next full page load. --}}
    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    {{-- What this business can be sent. One row per (enrolment, published
         form): that pair is what a link is for and what the grant is scoped to.
         Every button here re-checks the policy and the workspace server-side;
         hiding one is convenience, never authorization. --}}
    <x-ui.card title="Send a form to this business"
               subtitle="A link opens one form, expires, and is not a login. The business's own contact receives it."
               class="mb-5">
        <x-ui.table :headings="['Form', 'Version', 'Batch', 'Customer link', '>']" class="shadow-none ring-0">
            @forelse ($sendable as $row)
                @php
                    $grant = $row->grant;
                    $linkIsLive = $grant
                        && $grant->revoked_at === null
                        && $grant->expires_at?->isFuture()
                        && $grant->use_count < $grant->max_uses;
                @endphp

                <tr wire:key="sendable-{{ $row->enrollment->id }}-{{ $row->template->id }}" class="hover:bg-elevated">
                    <x-ui.td class="font-medium">{{ $row->template->name }}</x-ui.td>
                    <x-ui.td muted>v{{ $row->version?->version_number }}</x-ui.td>
                    <x-ui.td muted>{{ $row->enrollment->batch?->code }}</x-ui.td>

                    <x-ui.td>
                        @if (! $grant)
                            <span class="text-ink-3">Not sent</span>
                        @else
                            @if ($grant->revoked_at)
                                <x-ui.badge tone="danger">Withdrawn</x-ui.badge>
                            @elseif ($grant->use_count >= $grant->max_uses)
                                <x-ui.badge tone="success">Used</x-ui.badge>
                            @elseif ($grant->expires_at?->isPast())
                                <x-ui.badge tone="warning">Expired</x-ui.badge>
                            @else
                                <x-ui.badge tone="success">Active</x-ui.badge>
                            @endif

                            <div class="mt-1 text-xs text-ink-3">
                                <div class="truncate">Sent to {{ $grant->customerContact?->email ?? '—' }}</div>
                                <div>
                                    {{ $grant->issued_at?->format('d M Y H:i') }}
                                    @if ($linkIsLive)
                                        · expires {{ $grant->expires_at?->format('d M Y') }}
                                    @endif
                                </div>
                            </div>
                        @endif
                    </x-ui.td>

                    <x-ui.td align="right">
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            @can('create', App\Models\AccessGrant::class)
                                @if ($row->sendable)
                                    {{-- wire:loading disables the button while the send is in
                                         flight, so a double click cannot start a second one.
                                         The service revokes any live grant before issuing
                                         anyway, so even a race leaves exactly one live link. --}}
                                    <x-ui.button size="sm"
                                                 :variant="$linkIsLive ? 'secondary' : 'primary'"
                                                 wire:click="sendFormLink({{ $row->enrollment->id }}, {{ $row->template->id }})"
                                                 wire:loading.attr="disabled"
                                                 wire:target="sendFormLink({{ $row->enrollment->id }}, {{ $row->template->id }})">
                                        {{ $grant ? 'Resend form link' : 'Send form link' }}
                                    </x-ui.button>
                                @endif
                            @endcan

                            @if ($linkIsLive)
                                @can('revoke', $grant)
                                    <x-ui.button size="sm"
                                                 wire:click="revokeFormLink({{ $row->enrollment->id }}, {{ $row->template->id }})"
                                                 wire:loading.attr="disabled"
                                                 wire:target="revokeFormLink({{ $row->enrollment->id }}, {{ $row->template->id }})">
                                        Revoke link
                                    </x-ui.button>
                                @endcan
                            @endif
                        </div>

                        <x-ui.loading target="sendFormLink({{ $row->enrollment->id }}, {{ $row->template->id }})"
                                      label="Sending…" class="mt-1" />
                    </x-ui.td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" title="Nothing to send yet"
                                description="A business needs an active enrolment and a published form before it can be sent a link." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.table :headings="['Form', 'Version', 'Batch', 'Status', 'Submitted', 'Scores', '>']">
        @forelse ($submissions as $submission)
            <tr wire:key="submission-{{ $submission->id }}" class="hover:bg-elevated">
                <x-ui.td class="font-medium">{{ $submission->formVersion?->formTemplate?->name }}</x-ui.td>
                <x-ui.td muted>v{{ $submission->formVersion?->version_number }}</x-ui.td>
                <x-ui.td muted>{{ $submission->enrollment?->batch?->code }}</x-ui.td>
                <x-ui.td><x-ui.status-badge :status="$submission->status" /></x-ui.td>
                <x-ui.td muted>{{ $submission->submitted_at?->format('d M Y') ?? '—' }}</x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $submission->scores_count ?: '—' }}</x-ui.td>
                <x-ui.td align="right">
                    <x-ui.button size="sm" :href="route('forms.show', $submission)">
                        {{ $submission->isDraft() ? 'Continue' : 'View' }}
                    </x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="7" title="No forms started"
                            description="Start an instrument against one of this business's enrolments. It binds to the published version, so what was asked stays answerable later." />
        @endforelse
    </x-ui.table>

    <x-ui.modal :show="$starting" title="Start a form" close="$set('starting', false)">
        <form wire:submit="start" class="space-y-4">
            <x-ui.field label="Enrolment" required :error="$errors->first('enrollmentId')">
                <x-ui.select wire:model="enrollmentId">
                    <option value="">Choose an enrolment…</option>
                    @foreach ($enrollments as $enrollment)
                        <option value="{{ $enrollment->id }}">
                            {{ $enrollment->batch?->name }} ({{ $enrollment->batch?->code }}) — {{ Str::headline($enrollment->status) }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Form" required
                        hint="Only forms with a published version are offered."
                        :error="$errors->first('templateId')">
                <x-ui.select wire:model="templateId">
                    <option value="">Choose a form…</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}">
                            {{ $template->name }}{{ $template->is_scored ? ' (scored)' : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($enrollments->isEmpty())
                <x-ui.alert tone="warning">
                    This business is not enrolled in a batch yet. A submission binds to an enrolment.
                </x-ui.alert>
            @elseif ($templates->isEmpty())
                <x-ui.alert tone="warning">
                    No form template has a published version yet.
                </x-ui.alert>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('starting', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit"
                             :disabled="$enrollments->isEmpty() || $templates->isEmpty()">Start</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
