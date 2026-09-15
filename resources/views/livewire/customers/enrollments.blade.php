<x-ui.workspace :customer="$customer" :tabs="$tabs" current="enrollments"
                :subtitle="'Which batches this business is part of, and how each run is going.'">
    <x-slot:actions>
        @can('create', App\Models\Enrollment::class)
            <x-ui.button size="sm" variant="primary" wire:click="startEnrolment">Assign to batch</x-ui.button>
        @endcan
    </x-slot:actions>

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="space-y-4">
        @forelse ($enrollments as $enrollment)
            <x-ui.card wire:key="enrollment-{{ $enrollment->id }}">
                <x-slot:title>
                    {{ $enrollment->batch?->program?->name ?? 'Programme' }} —
                    {{ $enrollment->batch?->name }}
                </x-slot:title>

                <x-slot:actions>
                    <x-ui.status-badge :status="$enrollment->status" />

                    @can('update', $enrollment)
                        @if ($enrollment->status === 'enrolled')
                            <x-ui.button size="sm" wire:click="complete({{ $enrollment->id }})"
                                         wire:confirm="Mark this enrolment complete?">Complete</x-ui.button>
                            <x-ui.button size="sm" variant="ghost"
                                         wire:click="startWithdrawal({{ $enrollment->id }})">Withdraw</x-ui.button>
                        @endif
                    @endcan
                </x-slot:actions>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <dl class="divide-y divide-line">
                        <x-ui.definition term="Batch code">
                            <span class="font-mono text-xs">{{ $enrollment->batch?->code }}</span>
                        </x-ui.definition>
                        <x-ui.definition term="Starts">
                            {{ $enrollment->batch?->starts_on?->format('d M Y') ?? '—' }}
                        </x-ui.definition>
                    </dl>

                    <dl class="divide-y divide-line">
                        <x-ui.definition term="Enrolled">
                            {{ $enrollment->enrolled_at?->format('d M Y') }}
                        </x-ui.definition>
                        <x-ui.definition term="Payment due">
                            {{ $enrollment->payment_due_date?->format('d M Y') ?? '—' }}
                        </x-ui.definition>
                    </dl>

                    <dl class="divide-y divide-line">
                        <x-ui.definition term="Attendance marks">{{ $enrollment->attendances_count }}</x-ui.definition>
                        <x-ui.definition term="Form submissions">{{ $enrollment->form_submissions_count }}</x-ui.definition>
                    </dl>

                    <dl class="divide-y divide-line">
                        <x-ui.definition term="Assignment submissions">
                            {{ $enrollment->assignment_submissions_count }}
                        </x-ui.definition>
                        <x-ui.definition term="Open actions">{{ $enrollment->open_action_items_count }}</x-ui.definition>
                    </dl>
                </div>

                @if ($enrollment->status === 'withdrawn' && $enrollment->withdrawal_reason)
                    <x-ui.alert tone="warning" class="mt-4" title="Withdrawn">
                        {{ $enrollment->withdrawal_reason }}
                    </x-ui.alert>
                @endif

                <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
                    @can('sessions.view')
                        <x-ui.button size="sm" :href="route('customers.sessions', $customer)">Sessions</x-ui.button>
                    @endcan
                    @can('attendance.view')
                        <x-ui.button size="sm" :href="route('customers.attendance', $customer)">Attendance</x-ui.button>
                    @endcan
                    @can('assignments.view')
                        <x-ui.button size="sm" :href="route('customers.assignments', $customer)">Assignments</x-ui.button>
                    @endcan
                    @can('forms.view')
                        <x-ui.button size="sm" :href="route('customers.forms', $customer)">Forms</x-ui.button>
                    @endcan
                </div>

                <p class="mt-3 text-xs text-ink-3">
                    Progress is not shown as a single percentage: attendance weighting and completion
                    weighting are open client decisions, and the counts above are facts rather than a
                    formula.
                </p>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty title="No batch assigned yet"
                            description="Assign this business to a batch and they are an active programme participant straight away. Sessions, assignments and trackers all follow from the batch." />
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.modal :show="$enrolling" title="Assign to a batch" close="$set('enrolling', false)">
        <form wire:submit="enrol" class="space-y-4">
            <x-ui.field label="Batch" required :error="$errors->first('batchId')">
                <x-ui.select wire:model="batchId">
                    <option value="">Choose a batch…</option>
                    @foreach ($availableBatches as $batch)
                        <option value="{{ $batch->id }}">
                            {{ $batch->program?->name }} — {{ $batch->name }} ({{ $batch->code }})
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($availableBatches->isEmpty())
                <x-ui.alert tone="info">
                    This business is already in every batch there is. Create a batch first, or check the
                    runs listed behind this dialog.
                </x-ui.alert>
            @endif

            <x-ui.field label="Payment due date"
                        hint="Optional, and never a condition of taking part — participation starts on save either way. Set it only to schedule the payment reminder."
                        :error="$errors->first('paymentDueDate')">
                <x-ui.input type="date" wire:model="paymentDueDate" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('enrolling', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit" :disabled="$availableBatches->isEmpty()">Assign to batch</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$withdrawingId !== null" title="Withdraw enrolment" close="$set('withdrawingId', null)">
        <form wire:submit="withdraw" class="space-y-4">
            <p class="text-sm text-ink-2">
                Withdrawal is recorded with its reason and stays on the enrolment. Nothing already
                submitted is removed.
            </p>

            <x-ui.field label="Reason" required :error="$errors->first('withdrawalReason')">
                <x-ui.textarea wire:model="withdrawalReason" rows="3" />
            </x-ui.field>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" wire:click="$set('withdrawingId', null)">Cancel</x-ui.button>
                <x-ui.button variant="danger" type="submit">Withdraw</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-ui.workspace>
