<div>
    <x-ui.page-header title="Sessions"
                      subtitle="Scheduled sessions across every batch. Sessions 1–6 are the delivered programme." />

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

        <x-ui.select wire:model.live="sequence" class="w-auto">
            <option value="">All sessions</option>
            @foreach ($sequences as $value)
                <option value="{{ $value }}">Session {{ $value }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="status,batchId,sequence" />
    </x-ui.filters>

    <x-ui.table :headings="['#', 'Session', 'Batch', 'Planned', 'Actual', 'Status', 'Marks', '>']">
        @forelse ($sessions as $session)
            <tr wire:key="session-{{ $session->id }}" class="hover:bg-slate-50">
                <x-ui.td muted class="tabular-nums">{{ $session->sessionTemplate?->sequence }}</x-ui.td>
                <x-ui.td class="font-medium">{{ $session->sessionTemplate?->title }}</x-ui.td>
                <x-ui.td muted>{{ $session->batch?->code }}</x-ui.td>
                <x-ui.td muted>{{ $session->planned_date?->format('d M Y') }}</x-ui.td>
                <x-ui.td muted>{{ $session->actual_date?->format('d M Y') ?? '—' }}</x-ui.td>
                <x-ui.td><x-ui.status-badge :status="$session->status" /></x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $session->attendances_count }}</x-ui.td>
                <x-ui.td align="right">
                    <x-ui.button size="sm" :href="route('sessions.show', $session)">Open</x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="8" title="No sessions match"
                            description="Sessions are scheduled against a batch from its programme's curriculum." />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $sessions->links() }}</div>
</div>
