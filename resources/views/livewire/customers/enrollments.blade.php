<x-ui.workspace :customer="$customer" :tabs="$tabs" current="enrollments"
                :subtitle="'Programme enrolments and their lifecycle.'">
    <x-slot:actions>
        @can('create', App\Models\Enrollment::class)
            <x-ui.button size="sm" variant="primary" wire:click="startEnrolment">Enrol in batch</x-ui.button>
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
                    <dl class="divide-y divide-slate-100">
                        <x-ui.definition term="Batch code">
                            <span class="font-mono text-xs">{{ $enrollment->batch?->code }}</span>
                        </x-ui.definition>
                        <x-ui.definition term="Starts">
                            {{ $enrollment->batch?->starts_on?->format('d M Y') ?? '—' }}
                        </x-ui.definition>
                    </dl>

                    <dl class="divide-y divide-slate-100">
                        <x-ui.definition term="Enrolled">
                            {{ $enrollment->enrolled_at?->format('d M Y') }}
                        </x-ui.definition>
                        <x-ui.definition term="Payment due">
                            {{ $enrollment->payment_due_date?->format('d M Y') ?? '—' }}
                        </x-ui.definition>
                    </dl>

                    <dl class="divide-y divide-slate-100">
                        <x-ui.definition term="Attendance marks">{{ $enrollment->attendances_count }}</x-ui.definition>
                        <x-ui.definition term="Form submissions">{{ $enrollment->form_submissions_count }}</x-ui.definition>
                    </dl>

                    <dl class="divide-y divide-slate-100">
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

                <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
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

                <p class="mt-3 text-xs text-slate-500">
                    Progress is not shown as a single percentage: attendance weighting and completion
                    weighting are open client decisions, and the counts above are facts rather than a
                    formula.
                </p>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty title="Not enrolled in any batch"
                            description="A business joins the programme by being enrolled in a batch. Everything else — sessions, assignments, trackers — hangs off that enrolment." />
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.modal :show="$enrolling" title="Enrol in a batch" close="$set('enrolling', false)">
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
                    There is no batch this business is not already in. Create a batch first, or check the
                    existing enrolments.
                </x-ui.alert>
            @endif

            <x-ui.field label="Payment due date" hint="Optional. Drives the payment reminder if set."
                        :error="$errors->first('paymentDueDate')">
                <x-ui.input type="date" wire:model="paymentDueDate" />
            </x-ui.field>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" wire:click="$set('enrolling', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit" :disabled="$availableBatches->isEmpty()">Enrol</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$withdrawingId !== null" title="Withdraw enrolment" close="$set('withdrawingId', null)">
        <form wire:submit="withdraw" class="space-y-4">
            <p class="text-sm text-slate-600">
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
