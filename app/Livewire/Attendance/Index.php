<?php

declare(strict_types=1);

namespace App\Livewire\Attendance;

use App\Models\Batch;
use App\Models\SessionAttendance;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The attendance register across sessions.
 *
 * RAW STATUSES, DELIBERATELY. This screen shows what was marked, who marked
 * it, and when it was amended. It shows NO percentage anywhere.
 *
 * The reason is not an oversight: the weighting of `late` and `excused` is an
 * unresolved client decision, AttendanceWeighting has no implementation bound,
 * and AttendanceCalculator therefore cannot be constructed. A percentage here
 * would be a formula this application has never been given - and a wrong
 * attendance figure in front of a participant is worse than no figure.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public ?int $batchId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SessionAttendance::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.attendance.index', [
            'marks' => $this->results(),
            'statuses' => SessionAttendance::STATUSES,
            'batches' => Batch::query()->whereNull('archived_at')->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'summary' => $this->summary(),
        ])->layout('components.layouts.app', ['title' => 'Attendance']);
    }

    /**
     * @return LengthAwarePaginator<int, SessionAttendance>
     */
    private function results(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->with([
                'sessionInstance.batch:id,name,code',
                'sessionInstance.sessionTemplate:id,sequence,title',
                'enrollment.customer:id,name,code',
                'markedBy:id,name',
            ])
            ->latest('marked_at')
            ->paginate(25);
    }

    /**
     * Counts per status, which are facts. Not a rate.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        return $this->baseQuery()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * @return Builder<SessionAttendance>
     */
    private function baseQuery()
    {
        return SessionAttendance::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->batchId, fn ($q) => $q->whereHas(
                'sessionInstance',
                fn ($s) => $s->where('batch_id', $this->batchId),
            ));
    }
}
