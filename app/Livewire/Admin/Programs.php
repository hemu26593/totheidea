<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\Batch;
use App\Models\Program;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Programmes: the containers batches and curricula belong to.
 */
class Programs extends Component
{
    use ReportsDomainFailures;

    public bool $creating = false;

    public string $name = '';

    public string $code = '';

    public string $description = '';

    public int $sessionCount = 6;

    public function mount(): void
    {
        $this->authorize('viewAny', Batch::class);
    }

    public function startCreate(): void
    {
        $this->authorize('create', Batch::class);

        $this->reset(['name', 'code', 'description']);
        $this->sessionCount = 6;
        $this->creating = true;
    }

    public function create(): void
    {
        $this->authorize('create', Batch::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'unique:programs,code'],
            'description' => ['nullable', 'string', 'max:2000'],
            // The delivered programme is six sessions. The field is editable
            // rather than fixed, but nothing in this UI builds sessions 7-18.
            'sessionCount' => ['required', 'integer', 'between:1,6'],
        ]);

        $saved = $this->runGuarded(function () use ($data): void {
            Program::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?: null,
                'session_count' => $data['sessionCount'],
            ]);
        }, 'Programme created.');

        if ($saved) {
            $this->creating = false;
        }
    }

    public function render(): View
    {
        return view('livewire.admin.programs', [
            'programs' => Program::query()
                ->withCount(['batches', 'sessionTemplates'])
                ->orderBy('name')
                ->get(),
        ])->layout('components.layouts.app', ['title' => 'Programmes']);
    }
}
