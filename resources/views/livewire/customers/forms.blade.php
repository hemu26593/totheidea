<x-ui.workspace :customer="$customer" :tabs="$tabs" current="forms"
                subtitle="Intake and session instruments, rendered from their published versions.">
    <x-slot:actions>
        @can('create', App\Models\FormSubmission::class)
            <x-ui.button size="sm" variant="primary" wire:click="startSubmission">Start a form</x-ui.button>
        @endcan
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.table :headings="['Form', 'Version', 'Batch', 'Status', 'Submitted', 'Scores', '>']">
        @forelse ($submissions as $submission)
            <tr wire:key="submission-{{ $submission->id }}" class="hover:bg-slate-50">
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
