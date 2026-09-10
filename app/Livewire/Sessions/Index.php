<?php

declare(strict_types=1);

namespace App\Livewire\Sessions;

use App\Models\Batch;
use App\Models\SessionInstance;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every scheduled session, across batches.
 *
 * The sequence filter offers 1-6 because that is the delivered programme.
 * Sessions 7-18 are not built; nothing here creates a session template, so the
 * list simply shows what the curriculum actually contains.
 */
class Index extends Component
{
    use WithPagination;

    /** The delivered programme. */
    public const SESSION_SEQUENCES = [1, 2, 3, 4, 5, 6];

    #[Url]
    public string $status = 'all';

    #[Url]
    public ?int $batchId = null;

    #[Url]
    public ?int $sequence = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SessionInstance::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.sessions.index', [
            'sessions' => $this->results(),
            'batches' => Batch::query()->whereNull('archived_at')->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'statuses' => SessionInstance::STATUSES,
            'sequences' => self::SESSION_SEQUENCES,
        ])->layout('components.layouts.app', ['title' => 'Sessions']);
    }

    /**
     * @return LengthAwarePaginator<int, SessionInstance>
     */
    private function results(): LengthAwarePaginator
    {
        return SessionInstance::query()
            ->with(['batch:id,name,code', 'sessionTemplate:id,sequence,title', 'conductedBy:id,name'])
            ->withCount('attendances')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->batchId, fn ($q) => $q->where('batch_id', $this->batchId))
            ->when($this->sequence, fn ($q) => $q->whereHas(
                'sessionTemplate',
                fn ($t) => $t->where('sequence', $this->sequence),
            ))
            ->orderByDesc('planned_date')
            ->paginate(20);
    }
}
