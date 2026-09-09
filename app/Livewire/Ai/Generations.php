<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The AI approval queue.
 *
 * Every stage of the lifecycle is visible and distinct - pending, succeeded,
 * failed, awaiting approval, approved, rejected - because collapsing them is
 * exactly how "an admin reviewed this" quietly becomes "the system decided
 * this".
 *
 * Failed generations are listed rather than hidden: a failure is provenance
 * too, and it is usually the case most worth reading.
 */
class Generations extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $purpose = 'all';

    public function mount(): void
    {
        $this->authorize('viewAny', AiGeneration::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.ai.generations', [
            'generations' => $this->results(),
            'statuses' => AiGenerationStatus::values(),
            'purposes' => AiPurpose::values(),
            'awaitingCount' => AiGeneration::query()->awaitingApproval()->count(),
        ])->layout('components.layouts.app', ['title' => 'AI generations']);
    }

    /**
     * @return LengthAwarePaginator<int, AiGeneration>
     */
    private function results(): LengthAwarePaginator
    {
        return AiGeneration::query()
            ->with(['customer:id,name,code', 'generatedBy:id,name', 'promptVersion:id,key,version_number', 'approval.decidedBy'])
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->purpose !== 'all', fn ($q) => $q->where('purpose', $this->purpose))
            ->latest('generated_at')
            ->paginate(20);
    }
}
