<div>
    <x-ui.page-header :title="$batch->name"
                      :subtitle="$batch->program?->name"
                      :breadcrumbs="['Batches' => route('batches.index'), $batch->name => null]">
        <x-slot:actions>
            <x-ui.status-badge :status="$batch->status" />
            <span class="rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600">{{ $batch->code }}</span>

            @can('create', App\Models\SessionInstance::class)
                <x-ui.button size="sm" variant="primary" wire:click="startScheduling">Schedule session</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-ui.metric label="Enrolled" :value="$enrollments->where('status', 'enrolled')->count()" />
        <x-ui.metric label="Capacity" :value="$batch->capacity ?? '—'" hint="Blank means uncapped" />
        <x-ui.metric label="Sessions" :value="$sessions->count()" />
        <x-ui.metric label="Starts" :value="$batch->starts_on?->format('d M Y') ?? '—'" />
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card title="Sessions" subtitle="Scheduled against this batch." :padding="false">
            <x-ui.table :headings="['#', 'Session', 'Planned', 'Status', 'Marks', '>']" class="rounded-none shadow-none ring-0">
                @forelse ($sessions as $session)
                    <tr wire:key="session-{{ $session->id }}">
                        <x-ui.td muted class="tabular-nums">{{ $session->sessionTemplate?->sequence }}</x-ui.td>
                        <x-ui.td class="font-medium">{{ $session->sessionTemplate?->title }}</x-ui.td>
                        <x-ui.td muted>{{ $session->planned_date?->format('d M Y') }}</x-ui.td>
                        <x-ui.td><x-ui.status-badge :status="$session->status" /></x-ui.td>
                        <x-ui.td muted class="tabular-nums">{{ $session->attendances_count }}</x-ui.td>
                        <x-ui.td align="right">
                            <x-ui.button size="sm" :href="route('sessions.show', $session)">Open</x-ui.button>
                        </x-ui.td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" title="No sessions scheduled"
                                    description="Schedule sessions from this programme's curriculum." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Roster" subtitle="Businesses enrolled in this batch." :padding="false">
            <x-ui.table :headings="['Customer', 'Code', 'Status', '>']" class="rounded-none shadow-none ring-0">
                @forelse ($enrollments as $enrollment)
                    <tr wire:key="roster-{{ $enrollment->id }}">
                        <x-ui.td class="font-medium">{{ $enrollment->customer?->name }}</x-ui.td>
                        <x-ui.td muted><span class="font-mono text-xs">{{ $enrollment->customer?->code }}</span></x-ui.td>
                        <x-ui.td><x-ui.status-badge :status="$enrollment->status" /></x-ui.td>
                        <x-ui.td align="right">
                            @can('customers.view')
                                <x-ui.button size="sm" :href="route('customers.show', $enrollment->customer_id)">Open</x-ui.button>
                            @endcan
                        </x-ui.td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" title="Nobody enrolled yet" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$scheduling" title="Schedule a session" close="$set('scheduling', false)">
        <form wire:submit="schedule" class="space-y-4">
            <x-ui.field label="Session" required
                        hint="Only this programme's curriculum is offered — a session template from another programme is refused by the domain."
                        :error="$errors->first('templateId')">
                <x-ui.select wire:model="templateId">
                    <option value="">Choose a session…</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}">
                            Session {{ $template->sequence }} — {{ $template->title }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($templates->isEmpty())
                <x-ui.alert tone="warning">
                    This programme has no session templates yet. Define the curriculum first.
                </x-ui.alert>
            @endif

            <x-ui.field label="Planned date" required :error="$errors->first('plannedDate')">
                <x-ui.input type="date" wire:model="plannedDate" />
            </x-ui.field>

            <x-ui.field label="Venue" :error="$errors->first('venue')">
                <x-ui.input wire:model="venue" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('scheduling', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit" :disabled="$templates->isEmpty()">Schedule</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
