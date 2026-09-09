<div>
    <x-ui.page-header title="Assignments"
                      subtitle="Set at a session, released to a batch, reviewed by staff." />

    <x-ui.filters class="mb-4">
        <x-ui.select wire:model.live="status" class="w-auto">
            <option value="all">All statuses</option>
            @foreach ($statuses as $value)
                <option value="{{ $value }}">{{ Str::headline($value) }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wire:model.live="batchId" class="w-auto">
            <option value="">All batches</option>
            @foreach ($batches as $batch)
                <option value="{{ $batch->id }}">{{ $batch->name }} ({{ $batch->code }})</option>
            @endforeach
        </x-ui.select>

        <label class="flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
            <input type="checkbox" wire:model.live="overdueOnly"
                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-900">
            Overdue only
        </label>

        <x-ui.loading target="status,batchId,overdueOnly" />
    </x-ui.filters>

    <x-ui.table :headings="['Assignment', 'Session', 'Batch', 'Due', 'Status', 'Submitted', 'To review', '>']">
        @forelse ($assignments as $assignment)
            @php $overdue = $assignment->isReleased() && $assignment->due_at?->isPast(); @endphp

            <tr wire:key="assignment-{{ $assignment->id }}" class="hover:bg-slate-50">
                <x-ui.td class="font-medium">{{ $assignment->title }}</x-ui.td>
                <x-ui.td muted>
                    {{ $assignment->sessionInstance?->sessionTemplate?->sequence
                        ? 'Session '.$assignment->sessionInstance->sessionTemplate->sequence
                        : '—' }}
                </x-ui.td>
                <x-ui.td muted>{{ $assignment->sessionInstance?->batch?->code }}</x-ui.td>
                <x-ui.td>
                    <span class="{{ $overdue ? 'font-medium text-rose-700' : 'text-slate-600' }}">
                        {{ $assignment->due_at?->format('d M Y') ?? '—' }}
                    </span>
                </x-ui.td>
                <x-ui.td>
                    <x-ui.status-badge :status="$assignment->status" />
                    @if ($overdue)
                        {{-- A view over released assignments, not a fourth status. --}}
                        <x-ui.badge tone="danger" class="ml-1">Overdue</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $assignment->submitted_count }}</x-ui.td>
                <x-ui.td class="tabular-nums">
                    @if ($assignment->awaiting_review_count > 0)
                        <span class="font-medium text-amber-700">{{ $assignment->awaiting_review_count }}</span>
                    @else
                        <span class="text-slate-400">0</span>
                    @endif
                </x-ui.td>
                <x-ui.td align="right">
                    <x-ui.button size="sm" :href="route('assignments.show', $assignment)">Open</x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="8" title="No assignments match"
                            description="Assignments are staged at a session and released to its batch." />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $assignments->links() }}</div>
</div>
