<div>
    <x-ui.page-header title="Notifications"
                      subtitle="What the system tried to send, and what is holding it up." />

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($statuses as $value)
            <x-ui.metric :label="Str::headline($value)"
                         :value="number_format($summary[$value] ?? 0)"
                         :tone="$value === 'failed' ? 'danger' : 'default'" />
        @endforeach
    </div>

    <x-ui.card title="Triggers" subtitle="The nine reminders, and what each is waiting on." class="mb-5">
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($triggers as $trigger)
                <div class="rounded-md px-3 py-2 ring-1 ring-inset {{ $trigger['blocked'] ? 'bg-warning-wash ring-warning-line' : 'bg-raised ring-line' }}">
                    <p class="font-mono text-xs font-medium text-ink-2">{{ $trigger['key'] }}</p>

                    @if ($trigger['blocked'])
                        <p class="mt-1 text-xs text-warning">{{ $trigger['blocked'] }}</p>
                    @else
                        <p class="mt-1 text-xs text-ink-3">Active.</p>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="mt-3 border-t border-line pt-3 text-xs text-ink-3">
            Scheduling, eligibility and deduplication run on a schedule and are not duplicated here — two
            answers to "should this go out?" would mean two messages to the customer.
        </p>
    </x-ui.card>

    <x-ui.filters class="mb-4">
        <x-ui.select wire:model.live="status" class="w-auto">
            <option value="all">All statuses</option>
            @foreach ($statuses as $value)
                <option value="{{ $value }}">{{ Str::headline($value) }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wire:model.live="trigger" class="w-auto">
            <option value="all">All triggers</option>
            @foreach ($triggers as $t)
                <option value="{{ $t['key'] }}">{{ $t['key'] }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="status,trigger" />
    </x-ui.filters>

    <x-ui.table :headings="['Trigger', 'Channel', 'Recipient', 'Scheduled', 'Status', 'Attempts', '>']">
        @forelse ($dispatches as $dispatch)
            <tr wire:key="dispatch-{{ $dispatch->id }}" class="hover:bg-elevated">
                <x-ui.td><span class="font-mono text-xs">{{ $dispatch->trigger_key }}</span></x-ui.td>
                <x-ui.td><x-ui.badge>{{ Str::headline($dispatch->channel->value) }}</x-ui.badge></x-ui.td>
                <x-ui.td muted>
                    {{ Str::headline($dispatch->recipient_type) }} #{{ $dispatch->recipient_id }}
                </x-ui.td>
                <x-ui.td muted>{{ $dispatch->scheduled_for?->format('d M Y H:i') }}</x-ui.td>
                <x-ui.td>
                    <x-ui.status-badge :status="$dispatch->status" />
                    @if ($dispatch->error)
                        <p class="mt-0.5 max-w-xs truncate text-xs text-danger">{{ $dispatch->error }}</p>
                    @endif
                </x-ui.td>
                <x-ui.td muted class="tabular-nums">{{ $dispatch->attempts }}</x-ui.td>
                <x-ui.td align="right">
                    @if ($dispatch->status === App\Models\NotificationDispatch::STATUS_FAILED)
                        @can('manage', $dispatch)
                            <x-ui.button size="sm" wire:click="retry({{ $dispatch->id }})">Retry</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="7" title="Nothing dispatched"
                            description="Reminders appear here once the scheduler has swept and found something eligible." />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $dispatches->links() }}</div>
</div>
