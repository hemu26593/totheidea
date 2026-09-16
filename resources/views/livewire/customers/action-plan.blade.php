<x-ui.workspace :customer="$customer" :tabs="$tabs" current="action-plan"
                subtitle="Outstanding commitments with an owner and a due date. Distinct from the Day Plan and the Time Grid.">
    <x-slot:actions>
        @can('create', App\Models\ActionItem::class)
            <x-ui.button size="sm" variant="primary" wire:click="startAdding">Add action</x-ui.button>
        @endcan
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.filters class="mb-4">
        <x-ui.select wire:model.live="enrollmentId" class="w-auto">
            @foreach ($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}">{{ $enrollment->batch?->name }} ({{ $enrollment->batch?->code }})</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wire:model.live="status" class="w-auto">
            <option value="all">All statuses</option>
            @foreach ($statuses as $value)
                <option value="{{ $value }}">{{ Str::headline($value) }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.loading target="enrollmentId,status" />
    </x-ui.filters>

    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty title="Not enrolled"
                        description="Action items belong to an enrolment. Enrol this business in a batch first." />
        </x-ui.card>
    @else
        <x-ui.table :headings="['Action', 'Priority', 'Due', 'Source', 'Status', '>Actions']">
            @forelse ($items as $item)
                @php $overdue = $item->isOpen() && $item->due_date?->isPast(); @endphp

                <tr wire:key="action-{{ $item->id }}" class="hover:bg-elevated">
                    <x-ui.td>
                        <p class="font-medium">{{ $item->title }}</p>
                        @if ($item->description)
                            <p class="mt-0.5 text-xs text-ink-3">{{ Str::limit($item->description, 120) }}</p>
                        @endif
                    </x-ui.td>

                    <x-ui.td>
                        <x-ui.badge :tone="$item->priority === 'high' ? 'danger' : ($item->priority === 'low' ? 'neutral' : 'info')">
                            {{ Str::headline($item->priority) }}
                        </x-ui.badge>
                    </x-ui.td>

                    <x-ui.td>
                        <span class="{{ $overdue ? 'font-medium text-danger' : 'text-ink-2' }}">
                            {{ $item->due_date?->format('d M Y') ?? '—' }}
                        </span>
                    </x-ui.td>

                    <x-ui.td muted>
                        @if ($item->wasAutoFed())
                            {{-- The source the domain actually records. No weak-skill
                                 scoring is inferred: "weak" has no defined threshold. --}}
                            <x-ui.badge tone="accent">{{ class_basename($item->source_type) }}</x-ui.badge>
                        @else
                            <span class="text-xs">Added directly</span>
                        @endif
                    </x-ui.td>

                    <x-ui.td><x-ui.status-badge :status="$item->status" /></x-ui.td>

                    <x-ui.td align="right">
                        <div class="flex justify-end gap-1.5">
                            @if ($item->isOpen())
                                @can('update', $item)
                                    <x-ui.button size="sm"
                                                 wire:click="moveTo({{ $item->id }}, 'in_progress')">Start</x-ui.button>
                                @endcan
                            @endif

                            @unless ($item->isDone() || $item->status === 'dropped')
                                @can('complete', $item)
                                    <x-ui.button size="sm" variant="primary"
                                                 wire:click="moveTo({{ $item->id }}, 'done')">Done</x-ui.button>
                                @endcan
                                @can('update', $item)
                                    <x-ui.button size="sm" variant="ghost"
                                                 wire:click="moveTo({{ $item->id }}, 'dropped')"
                                                 wire:confirm="Drop this action?">Drop</x-ui.button>
                                @endcan
                            @endunless
                        </div>
                    </x-ui.td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" title="No actions"
                                description="Add one, or change the status filter." />
            @endforelse
        </x-ui.table>
    @endif

    <x-ui.modal :show="$adding" title="Add an action" close="$set('adding', false)">
        <form wire:submit="add" class="space-y-4">
            <x-ui.field label="Action" required :error="$errors->first('title')">
                <x-ui.input wire:model="title" />
            </x-ui.field>

            <x-ui.field label="Detail" :error="$errors->first('description')">
                <x-ui.textarea wire:model="description" rows="3" />
            </x-ui.field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Due date" :error="$errors->first('dueDate')">
                    <x-ui.input type="date" wire:model="dueDate" />
                </x-ui.field>

                <x-ui.field label="Priority" required :error="$errors->first('priority')">
                    <x-ui.select wire:model="priority">
                        @foreach ($priorities as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('adding', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit">Add action</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
