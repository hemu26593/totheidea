<?php

declare(strict_types=1);

namespace App\Livewire\Assignments;

use App\Models\AssignmentInstance;
use App\Models\Batch;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Assignments across batches.
 *
 * "Overdue" is a FILTER, not a status. The domain has three assignment
 * statuses - draft, released, closed - and inventing a fourth would put a
 * state in the UI that no service can write or read.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public ?int $batchId = null;

    /** A view over released assignments past their due date. */
    #[Url]
    public bool $overdueOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', AssignmentInstance::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedOverdueOnly(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.assignments.index', [
            'assignments' => $this->results(),
            'statuses' => AssignmentInstance::STATUSES,
            'batches' => Batch::query()->whereNull('archived_at')->orderByDesc('starts_on')->get(['id', 'name', 'code']),
        ])->layout('components.layouts.app', ['title' => 'Assignments']);
    }

    /**
     * @return LengthAwarePaginator<int, AssignmentInstance>
     */
    private function results(): LengthAwarePaginator
    {
        return AssignmentInstance::query()
            ->with(['sessionInstance.batch:id,name,code', 'sessionInstance.sessionTemplate:id,sequence,title'])
            ->withCount([
                'submissions',
                'submissions as submitted_count' => fn ($q) => $q->whereIn('status', ['submitted', 'accepted']),
                'submissions as awaiting_review_count' => fn ($q) => $q->where('status', 'submitted'),
            ])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->batchId, fn ($q) => $q->whereHas(
                'sessionInstance',
                fn ($s) => $s->where('batch_id', $this->batchId),
            ))
            ->when($this->overdueOnly, fn ($q) => $q
                ->where('status', AssignmentInstance::STATUS_RELEASED)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()))
            ->orderByDesc('due_at')
            ->paginate(20);
    }
}
