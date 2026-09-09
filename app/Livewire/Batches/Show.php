<?php

declare(strict_types=1);

namespace App\Livewire\Batches;

use App\Domain\Sessions\SessionSchedulingService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A batch: its roster, and the sessions scheduled against it.
 *
 * Scheduling goes through SessionSchedulingService, which holds the invariant
 * a foreign key cannot express - the template's programme must be the batch's
 * programme. Nothing here writes a session_instances row.
 */
class Show extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public int $batchId;

    public bool $scheduling = false;

    public ?int $templateId = null;

    public string $plannedDate = '';

    public string $venue = '';

    public function mount(Batch $batch): void
    {
        $this->authorize('view', $batch);

        $this->batchId = (int) $batch->getKey();
    }

    public function startScheduling(): void
    {
        $this->authorize('create', SessionInstance::class);

        $this->reset(['templateId', 'plannedDate', 'venue']);
        $this->scheduling = true;
    }

    public function schedule(SessionSchedulingService $scheduler): void
    {
        $this->authorize('create', SessionInstance::class);

        $this->validate([
            'templateId' => ['required', 'integer', 'exists:session_templates,id'],
            'plannedDate' => ['required', 'date'],
            'venue' => ['nullable', 'string', 'max:150'],
        ]);

        $batch = $this->batch();
        $template = SessionTemplate::query()->findOrFail($this->templateId);

        $saved = $this->runGuarded(function () use ($scheduler, $batch, $template): void {
            $scheduler->schedule(
                $batch,
                $template,
                $this->plannedDate,
                auth()->user(),
                $this->venue !== '' ? $this->venue : null,
            );
        }, 'Session scheduled.');

        if ($saved) {
            $this->scheduling = false;
        }
    }

    public function render(): View
    {
        $batch = $this->batch();

        return view('livewire.batches.show', [
            'batch' => $batch,
            'enrollments' => $batch->enrollments()->with('customer:id,name,code')->get(),
            'sessions' => SessionInstance::query()
                ->where('batch_id', $batch->getKey())
                ->with('sessionTemplate:id,sequence,title')
                ->withCount('attendances')
                ->orderBy('planned_date')
                ->get(),
            // Only templates from THIS batch's programme are offered: the
            // service refuses any other, so offering one would only produce an
            // error the user cannot act on.
            'templates' => SessionTemplate::query()
                ->where('program_id', $batch->program_id)
                ->whereNull('archived_at')
                ->orderBy('sequence')
                ->get(),
        ])->layout('components.layouts.app', ['title' => $batch->name]);
    }

    private function batch(): Batch
    {
        return Batch::query()->with('program')->findOrFail($this->batchId);
    }
}
