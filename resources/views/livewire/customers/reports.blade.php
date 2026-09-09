<x-ui.workspace :customer="$customer" :tabs="$tabs" current="reports"
                subtitle="Participant progress reports for this business, and what has been delivered.">
    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty title="Not enrolled"
                        description="A participant progress report is about an enrolment." />
        </x-ui.card>
    @else
        <x-ui.card title="Generate" subtitle="Both formats read the same computed payload, so they always agree."
                   class="mb-5">
            <div class="flex flex-wrap items-end gap-3">
                <x-ui.field label="Enrolment" class="min-w-64">
                    <x-ui.select wire:model="enrollmentId">
                        @foreach ($enrollments as $enrollment)
                            <option value="{{ $enrollment->id }}">
                                {{ $enrollment->batch?->name }} ({{ $enrollment->batch?->code }})
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @can('export', App\Models\ReportArtifact::class)
                    <x-ui.button variant="primary" wire:click="export('pdf')">Export PDF</x-ui.button>
                    <x-ui.button wire:click="export('csv')">Export CSV</x-ui.button>
                @else
                    <p class="pb-2 text-xs text-slate-500">
                        Exporting writes a file that leaves the system, which is a separate permission.
                    </p>
                @endcan

                <x-ui.loading target="export" label="Generating…" class="pb-2" />
            </div>
        </x-ui.card>

        <x-ui.card title="Delivered" subtitle="Every report ever issued about this business." :padding="false">
            <x-ui.table :headings="['Report', 'Format', 'Generated', 'By', 'Checksum', '>']"
                        class="rounded-none shadow-none ring-0">
                @forelse ($artifacts as $artifact)
                    <tr wire:key="artifact-{{ $artifact->id }}">
                        <x-ui.td class="font-medium">{{ Str::headline($artifact->report_key) }}</x-ui.td>
                        <x-ui.td><x-ui.badge>{{ strtoupper($artifact->format) }}</x-ui.badge></x-ui.td>
                        <x-ui.td muted>{{ $artifact->generated_at?->format('d M Y H:i') }}</x-ui.td>
                        <x-ui.td muted>{{ $artifact->generatedBy?->name ?? 'System' }}</x-ui.td>
                        <x-ui.td muted>
                            <span class="font-mono text-xs">{{ substr($artifact->checksum_sha256, 0, 12) }}…</span>
                        </x-ui.td>
                        <x-ui.td align="right">
                            @can('download', $artifact)
                                <x-ui.button size="sm" wire:click="download({{ $artifact->id }})">Download</x-ui.button>
                            @endcan
                        </x-ui.td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" title="Nothing delivered yet" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif
</x-ui.workspace>
