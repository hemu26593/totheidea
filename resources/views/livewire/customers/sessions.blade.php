<x-ui.workspace :customer="$customer" :tabs="$tabs" current="sessions"
                subtitle="Sessions 1–6, as delivered to this business's batches.">
    <x-ui.table :headings="['#', 'Session', 'Batch', 'Planned', 'Actual', 'Status', 'This business', 'Assignments', '>']">
        @forelse ($sessions as $session)
            @php $mark = $marks[$session->id] ?? null; @endphp

            <tr wire:key="session-{{ $session->id }}" class="hover:bg-elevated">
                <x-ui.td muted class="tabular-nums">{{ $session->sessionTemplate?->sequence }}</x-ui.td>
                <x-ui.td>
                    <p class="font-medium">{{ $session->sessionTemplate?->title }}</p>
                    @if ($session->sessionTemplate?->theme)
                        <p class="text-xs text-ink-3">{{ $session->sessionTemplate->theme }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td muted>{{ $session->batch?->code }}</x-ui.td>
                <x-ui.td muted>{{ $session->planned_date?->format('d M Y') }}</x-ui.td>
                <x-ui.td muted>{{ $session->actual_date?->format('d M Y') ?? '—' }}</x-ui.td>
                <x-ui.td><x-ui.status-badge :status="$session->status" /></x-ui.td>
                <x-ui.td>
                    @if ($mark)
                        <x-ui.status-badge :status="$mark->status" />
                    @else
                        <span class="text-xs text-ink-3">Not marked</span>
                    @endif
                </x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $session->assignment_instances_count }}</x-ui.td>
                <x-ui.td align="right">
                    @can('sessions.view')
                        <x-ui.button size="sm" :href="route('sessions.show', $session)">Open</x-ui.button>
                    @endcan
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="9" title="No sessions scheduled"
                            description="Sessions are scheduled against the batch this business is enrolled in." />
        @endforelse
    </x-ui.table>
</x-ui.workspace>
