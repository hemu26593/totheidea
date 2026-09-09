<?php

declare(strict_types=1);

namespace App\Livewire\Batches;

use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\Program;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Batches: a programme run, and the thing enrolments attach to.
 */
class Index extends Component
{
    use ReportsDomainFailures;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $programId = null;

    public bool $creating = false;

    public string $name = '';

    public string $code = '';

    public ?int $newProgramId = null;

    public string $startsOn = '';

    public string $endsOn = '';

    public ?int $capacity = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Batch::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->authorize('create', Batch::class);

        $this->reset(['name', 'code', 'newProgramId', 'startsOn', 'endsOn', 'capacity']);
        $this->creating = true;
    }

    /**
     * Batches have no domain service of their own - there is no rule beyond
     * the schema, and the capacity check lives in EnrollmentService where it
     * is actually applied. Creation is therefore a plain mass-assignment
     * through the model's own fillable list.
     */
    public function create(): void
    {
        $this->authorize('create', Batch::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'unique:batches,code'],
            'newProgramId' => ['required', 'integer', 'exists:programs,id'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $saved = $this->runGuarded(function () use ($data): void {
            Batch::create([
                'program_id' => $data['newProgramId'],
                'name' => $data['name'],
                'code' => $data['code'],
                'starts_on' => $data['startsOn'],
                'ends_on' => $data['endsOn'] ?: null,
                'capacity' => $data['capacity'],
            ]);
        }, 'Batch created.');

        if ($saved) {
            $this->creating = false;
        }
    }

    public function render(): View
    {
        return view('livewire.batches.index', [
            'batches' => $this->results(),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('components.layouts.app', ['title' => 'Batches']);
    }

    /**
     * @return LengthAwarePaginator<int, Batch>
     */
    private function results(): LengthAwarePaginator
    {
        return Batch::query()
            ->with('program:id,name')
            ->withCount([
                'enrollments',
                'enrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'enrolled'),
            ])
            ->when($this->search !== '', function ($query): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $this->search).'%';

                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->when($this->programId, fn ($q) => $q->where('program_id', $this->programId))
            ->orderByDesc('starts_on')
            ->paginate(20);
    }
}
