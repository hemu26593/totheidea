<div>
    <x-ui.page-header title="Attendance"
                      subtitle="What was marked, by whom, and when it was amended." />

    <x-ui.alert tone="info" class="mb-5" title="No attendance percentage is shown anywhere">
        The weighting of <em>late</em> and <em>excused</em> is an open client decision, so this
        application has no authoritative attendance formula. Counts below are facts; a percentage would
        be an invented rule. Once the weighting is decided, the figure appears here and in reports
        automatically.
    </x-ui.alert>

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($statuses as $value)
            <x-ui.metric :label="Str::headline($value)"
                         :value="number_format($summary[$value] ?? 0)"
                         hint="Marks recorded" />
        @endforeach
    </div>

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

        <x-ui.loading target="status,batchId" />
    </x-ui.filters>

    <x-ui.table :headings="['Participant', 'Session', 'Batch', 'Status', 'Marked', 'By', '>']">
        @forelse ($marks as $mark)
            <tr wire:key="mark-{{ $mark->id }}" class="hover:bg-slate-50">
                <x-ui.td class="font-medium">{{ $mark->enrollment?->customer?->name }}</x-ui.td>
                <x-ui.td muted>
                    Session {{ $mark->sessionInstance?->sessionTemplate?->sequence }} —
                    {{ $mark->sessionInstance?->sessionTemplate?->title }}
                </x-ui.td>
                <x-ui.td muted>{{ $mark->sessionInstance?->batch?->code }}</x-ui.td>
                <x-ui.td>
                    <x-ui.status-badge :status="$mark->status" />
                    @if ($mark->wasAmended())
                        <x-ui.badge tone="info" class="ml-1">Amended</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td muted>{{ $mark->marked_at?->format('d M Y H:i') }}</x-ui.td>
                <x-ui.td muted>{{ $mark->markedBy?->name ?? 'External link' }}</x-ui.td>
                <x-ui.td align="right">
                    <x-ui.button size="sm" :href="route('sessions.show', $mark->session_instance_id)">Session</x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="7" title="No attendance recorded"
                            description="Attendance is marked on a session once it has actually been held." />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $marks->links() }}</div>
</div>
