<div>
    <x-ui.page-header title="Reports"
                      subtitle="Deterministic: the same data produces the same figures in PDF and in CSV." />

    @error('domain')
        <x-ui.alert tone="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card title="Generate a report" class="mb-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.field label="Report">
                <x-ui.select wire:model.live="reportKey">
                    @foreach ($reportKeys as $key)
                        <option value="{{ $key }}">{{ Str::headline($key) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="{{ $subjectType === App\Models\Batch::class ? 'Batch' : 'Participant' }}"
                        class="sm:col-span-2">
                <x-ui.select wire:model.live="subjectId">
                    <option value="">Choose…</option>
                    @foreach ($subjects as $subject)
                        <option value="{{ $subject->id }}">
                            @if ($subject instanceof App\Models\Batch)
                                {{ $subject->name }} ({{ $subject->code }})
                            @else
                                {{ $subject->customer?->name }} — {{ $subject->batch?->code }}
                            @endif
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
            @can('export', App\Models\ReportArtifact::class)
                <x-ui.button variant="primary" wire:click="export('pdf')" :disabled="! $subjectId">Export PDF</x-ui.button>
                <x-ui.button wire:click="export('csv')" :disabled="! $subjectId">Export CSV</x-ui.button>
            @else
                <p class="text-xs text-ink-3">
                    You can preview reports but not export them — writing a file that leaves the system is a
                    separate permission.
                </p>
            @endcan

            <x-ui.loading target="export" label="Generating…" />
        </div>
    </x-ui.card>

    @if ($preview)
        <x-ui.card :title="$preview->title" subtitle="Preview — computed, not stored." class="mb-5">
            @foreach ($preview->sections as $section)
                <div class="mb-5 last:mb-0" wire:key="preview-section-{{ $loop->index }}">
                    <h3 class="mb-2 text-sm font-semibold text-ink">{{ $section->heading }}</h3>

                    @if ($section->rows !== [])
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-line text-sm">
                                @if ($section->columns !== [])
                                    <thead class="bg-raised text-left text-xs uppercase tracking-wide text-ink-3">
                                        <tr>
                                            @foreach ($section->columns as $column)
                                                <th class="px-3 py-2 font-medium">{{ $column }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                @endif

                                <tbody class="divide-y divide-line">
                                    @foreach ($section->rows as $row)
                                        <tr>
                                            @foreach ($row as $cell)
                                                <td class="px-3 py-2 text-ink-2">{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @foreach ($section->notes as $note)
                        <p class="mt-2 text-xs text-ink-3">{{ $note }}</p>
                    @endforeach
                </div>
            @endforeach

            @if ($preview->narrative)
                <div class="mt-4 rounded-md bg-raised p-3 ring-1 ring-inset ring-line">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-3">Narrative</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-ink-2">{{ $preview->narrative }}</p>
                    <p class="mt-2 text-xs text-ink-3">
                        Prose attached alongside the figures above. It is never the source of a number.
                    </p>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card title="Delivered reports" subtitle="What was actually handed over, with its checksum."
               :padding="false">
        <x-ui.table :headings="['Report', 'Format', 'Generated', 'By', 'Checksum', '>']"
                    class="rounded-none shadow-none ring-0">
            @forelse ($artifacts as $artifact)
                <tr wire:key="artifact-{{ $artifact->id }}" class="hover:bg-elevated">
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
                <x-ui.empty-row :colspan="6" title="No reports generated yet"
                                description="A stored artifact is the record of what was handed over — it is never read back as a data source." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="mt-4">{{ $artifacts->links() }}</div>
</div>
