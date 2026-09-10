<x-ui.workspace :customer="$customer" :tabs="$tabs" current="attendance"
                subtitle="Every mark recorded for this business, with who recorded it.">
    <x-ui.alert tone="info" class="mb-5" title="Counts, not a percentage">
        The weighting of <em>late</em> and <em>excused</em> is an open client decision, so no attendance
        percentage is calculated anywhere in this application. The counts below are what was actually
        marked.
    </x-ui.alert>

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($statuses as $status)
            <x-ui.metric :label="Str::headline($status)" :value="$counts[$status] ?? 0" />
        @endforeach
    </div>

    <x-ui.table :headings="['Session', 'Batch', 'Date', 'Status', 'Marked by', 'Amended']">
        @forelse ($marks as $mark)
            <tr wire:key="mark-{{ $mark->id }}">
                <x-ui.td class="font-medium">
                    Session {{ $mark->sessionInstance?->sessionTemplate?->sequence }} —
                    {{ $mark->sessionInstance?->sessionTemplate?->title }}
                </x-ui.td>
                <x-ui.td muted>{{ $mark->sessionInstance?->batch?->code }}</x-ui.td>
                <x-ui.td muted>
                    {{ ($mark->sessionInstance?->actual_date ?? $mark->sessionInstance?->planned_date)?->format('d M Y') }}
                </x-ui.td>
                <x-ui.td><x-ui.status-badge :status="$mark->status" /></x-ui.td>
                <x-ui.td muted>{{ $mark->markedBy?->name ?? 'External link' }}</x-ui.td>
                <x-ui.td muted>
                    {{ $mark->wasAmended() ? $mark->amended_at?->format('d M Y') : '—' }}
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="6" title="No attendance recorded"
                            description="Attendance is marked on a session once it has been held." />
        @endforelse
    </x-ui.table>
</x-ui.workspace>
