<div>
    <x-ui.page-header title="Operations dashboard"
                      subtitle="Live counts from the operational tables. Nothing here is modelled or projected." />

    @if ($metrics === [])
        <x-ui.card>
            <x-ui.empty title="No operational data is visible to you"
                        description="Your role does not currently carry any of the permissions these figures are drawn from." />
        </x-ui.card>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
            @foreach ($metrics as $metric)
                <x-ui.metric :label="$metric['label']"
                             :value="number_format($metric['value'])"
                             :hint="$metric['hint'] ?? null"
                             :tone="($metric['value'] > 0 ? ($metric['tone'] ?? 'default') : 'default')"
                             :href="$metric['href'] ?? null" />
            @endforeach
        </div>
    @endif

    <div class="mt-6 grid gap-5 xl:grid-cols-2">
        @if ($attentionCustomers !== null)
            <x-ui.card title="Customers requiring attention"
                       subtitle="A recorded absence, or an intake still in draft."
                       :padding="false">
                <x-ui.table :headings="['Customer', 'Code', 'Enrolments', '>']" class="rounded-none shadow-none ring-0">
                    @forelse ($attentionCustomers as $customer)
                        <tr wire:key="attention-{{ $customer->id }}">
                            <x-ui.td class="font-medium">{{ $customer->name }}</x-ui.td>
                            <x-ui.td muted>{{ $customer->code }}</x-ui.td>
                            <x-ui.td muted>{{ $customer->open_enrollments_count }}</x-ui.td>
                            <x-ui.td align="right">
                                <x-ui.button size="sm" :href="route('customers.show', $customer)">Open</x-ui.button>
                            </x-ui.td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" title="Nothing outstanding"
                                        description="No absence or draft intake is recorded against any customer." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif

        @if ($upcomingSessions !== null)
            <x-ui.card title="Upcoming sessions" subtitle="Scheduled, from today onward." :padding="false">
                <x-ui.table :headings="['Session', 'Batch', 'Planned', '>']" class="rounded-none shadow-none ring-0">
                    @forelse ($upcomingSessions as $session)
                        <tr wire:key="upcoming-{{ $session->id }}">
                            <x-ui.td class="font-medium">
                                Session {{ $session->sessionTemplate?->sequence }} —
                                {{ $session->sessionTemplate?->title }}
                            </x-ui.td>
                            <x-ui.td muted>{{ $session->batch?->code }}</x-ui.td>
                            <x-ui.td muted>{{ $session->planned_date?->format('d M Y') }}</x-ui.td>
                            <x-ui.td align="right">
                                <x-ui.button size="sm" :href="route('sessions.show', $session)">Open</x-ui.button>
                            </x-ui.td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" title="No sessions scheduled" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif

        @if ($overdueAssignments !== null)
            <x-ui.card title="Overdue assignments" subtitle="Released and past their due date." :padding="false">
                <x-ui.table :headings="['Assignment', 'Batch', 'Due', '>']" class="rounded-none shadow-none ring-0">
                    @forelse ($overdueAssignments as $assignment)
                        <tr wire:key="overdue-{{ $assignment->id }}">
                            <x-ui.td class="font-medium">{{ $assignment->title }}</x-ui.td>
                            <x-ui.td muted>{{ $assignment->sessionInstance?->batch?->code }}</x-ui.td>
                            <x-ui.td>
                                <span class="text-danger">{{ $assignment->due_at?->format('d M Y') }}</span>
                            </x-ui.td>
                            <x-ui.td align="right">
                                <x-ui.button size="sm" :href="route('assignments.show', $assignment)">Open</x-ui.button>
                            </x-ui.td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" title="Nothing overdue" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif

        @if ($recentSubmissions !== null)
            <x-ui.card title="Recent form submissions" :padding="false">
                <x-ui.table :headings="['Customer', 'Form', 'Submitted']" class="rounded-none shadow-none ring-0">
                    @forelse ($recentSubmissions as $submission)
                        <tr wire:key="submission-{{ $submission->id }}">
                            <x-ui.td class="font-medium">{{ $submission->enrollment?->customer?->name }}</x-ui.td>
                            <x-ui.td muted>{{ $submission->formVersion?->formTemplate?->name }}</x-ui.td>
                            <x-ui.td muted>{{ $submission->submitted_at?->format('d M Y H:i') }}</x-ui.td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="3" title="No submissions yet" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif

        @if ($recentGenerations !== null)
            <x-ui.card title="Recent AI generations"
                       subtitle="Proposals awaiting a second pair of eyes."
                       :padding="false">
                <x-ui.table :headings="['Customer', 'Purpose', 'Status', 'By']" class="rounded-none shadow-none ring-0">
                    @forelse ($recentGenerations as $generation)
                        <tr wire:key="generation-{{ $generation->id }}">
                            <x-ui.td class="font-medium">{{ $generation->customer?->name }}</x-ui.td>
                            <x-ui.td muted>{{ Str::headline($generation->purpose->value) }}</x-ui.td>
                            <x-ui.td><x-ui.status-badge :status="$generation->status" /></x-ui.td>
                            <x-ui.td muted>{{ $generation->generatedBy?->name }}</x-ui.td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" title="No AI generations yet" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif

        @if ($recentReports !== null)
            <x-ui.card title="Recent reports" subtitle="What was actually delivered." :padding="false">
                <x-ui.table :headings="['Report', 'Format', 'Generated', 'By']" class="rounded-none shadow-none ring-0">
                    @forelse ($recentReports as $artifact)
                        <tr wire:key="artifact-{{ $artifact->id }}">
                            <x-ui.td class="font-medium">{{ Str::headline($artifact->report_key) }}</x-ui.td>
                            <x-ui.td><x-ui.badge>{{ strtoupper($artifact->format) }}</x-ui.badge></x-ui.td>
                            <x-ui.td muted>{{ $artifact->generated_at?->format('d M Y H:i') }}</x-ui.td>
                            <x-ui.td muted>{{ $artifact->generatedBy?->name ?? 'System' }}</x-ui.td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" title="No reports generated yet" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>

    <p class="mt-6 text-xs text-ink-3">
        Attendance percentage and completion scores are deliberately absent: their weighting is an
        open client decision, so no figure is shown rather than an invented one.
    </p>
</div>
