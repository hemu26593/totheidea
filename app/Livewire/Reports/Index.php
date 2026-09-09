<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Reporting\ReportArtifactService;
use App\Domain\Reporting\ReportData;
use App\Domain\Reporting\ReportRegistry;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\ReportArtifact;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Generating and retrieving reports.
 *
 * ONLY IMPLEMENTED BUILDERS ARE OFFERED. The list comes from ReportRegistry,
 * not from a hard-coded array and not from the report_artifacts vocabulary.
 * `diagnostic` is part of that vocabulary but has no builder, so it does not
 * appear here - asking for it would fail loudly, and offering a button that
 * fails is worse than not offering it.
 *
 * VIEW AND EXPORT ARE DIFFERENT PERMISSIONS, and the gap is the point. Preview
 * computes figures on screen (reports.view). Export writes a file that leaves
 * the system (reports.export), which is a data-exfiltration boundary - Staff
 * hold the first and not the second, so they see previews and no download
 * buttons.
 *
 * THE SUBJECT IS RESOLVED SERVER-SIDE. A subject id from a request is looked
 * up and its type checked against the builder's declared subject type; a batch
 * id passed where an enrolment belongs is refused by the domain rather than
 * silently reported on.
 */
class Index extends Component
{
    use ReportsDomainFailures;
    use WithPagination;

    #[Url]
    public string $reportKey = '';

    #[Url]
    public ?int $subjectId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ReportArtifact::class);

        $this->reportKey = $this->reportKey ?: (app(ReportRegistry::class)->keys()[0] ?? '');
    }

    public function updatedReportKey(): void
    {
        $this->subjectId = null;
    }

    public function export(string $format, ReportArtifactService $reports): void
    {
        $this->authorize('export', ReportArtifact::class);

        $this->validate([
            'reportKey' => ['required', 'string'],
            'subjectId' => ['required', 'integer'],
        ]);

        $subject = $this->subject();

        if ($subject === null) {
            $this->addError('domain', 'Choose what the report is about.');

            return;
        }

        $this->runGuarded(
            fn () => $reports->export($this->reportKey, $subject, auth()->user(), $format),
            strtoupper($format).' generated.',
        );
    }

    /**
     * Hand back the delivered bytes.
     *
     * The artifact is re-read and the policy re-consulted; the file is served
     * from a private disk through the application, never by URL.
     */
    public function download(int $artifactId)
    {
        $artifact = ReportArtifact::query()->findOrFail($artifactId);

        $this->authorize('download', $artifact);

        if (! Storage::disk($artifact->disk)->exists($artifact->path)) {
            $this->addError('domain', 'The stored report file is missing from the disk.');

            return null;
        }

        return Storage::disk($artifact->disk)->download(
            $artifact->path,
            $artifact->report_key.'.'.$artifact->format,
        );
    }

    public function render(): View
    {
        $registry = app(ReportRegistry::class);
        $builder = $this->reportKey !== '' && $registry->has($this->reportKey)
            ? $registry->get($this->reportKey)
            : null;

        return view('livewire.reports.index', [
            // Implemented builders only.
            'reportKeys' => $registry->keys(),
            'subjectType' => $builder?->subjectType(),
            'subjects' => $this->subjectOptions($builder?->subjectType()),
            'preview' => $this->preview(),
            'artifacts' => ReportArtifact::query()
                ->with('generatedBy:id,name')
                ->latest('generated_at')
                ->paginate(15),
        ])->layout('components.layouts.app', ['title' => 'Reports']);
    }

    /**
     * The on-screen figures, computed but not stored.
     */
    private function preview(): ?ReportData
    {
        $subject = $this->subject();

        if ($subject === null || $this->reportKey === '') {
            return null;
        }

        try {
            return app(ReportArtifactService::class)->generate($this->reportKey, $subject, auth()->user());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function subject(): ?Model
    {
        if ($this->subjectId === null || $this->reportKey === '') {
            return null;
        }

        $registry = app(ReportRegistry::class);

        if (! $registry->has($this->reportKey)) {
            return null;
        }

        // The builder declares what it reports on; the id is resolved against
        // that type rather than guessed from the request.
        $class = $registry->get($this->reportKey)->subjectType();

        return $class::query()->find($this->subjectId);
    }

    /**
     * @return Collection<int, Model>
     */
    private function subjectOptions(?string $subjectType): Collection
    {
        return match ($subjectType) {
            Enrollment::class => Enrollment::query()
                ->with(['customer:id,name', 'batch:id,name,code'])
                ->where('status', '!=', 'withdrawn')
                ->get(),
            Batch::class => Batch::query()->whereNull('archived_at')->orderByDesc('starts_on')->get(),
            default => collect(),
        };
    }
}
