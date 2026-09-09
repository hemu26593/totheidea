<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\FormTemplate;
use App\Models\SkillArea;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Skill areas: the dimensions a scored instrument reports against.
 *
 * NO THRESHOLDS AND NO BANDS ARE DEFINED HERE, because none has been decided.
 * A skill area is a name and a position; what counts as weak, average or
 * strong within it is an open client decision, and this screen does not
 * anticipate the answer with a field.
 */
class SkillAreas extends Component
{
    use ReportsDomainFailures;

    public bool $creating = false;

    public string $name = '';

    public string $key = '';

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('viewAny', FormTemplate::class);
    }

    public function startCreate(): void
    {
        $this->authorize('create', FormTemplate::class);

        $this->reset(['name', 'key', 'description']);
        $this->creating = true;
    }

    public function create(): void
    {
        $this->authorize('create', FormTemplate::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'unique:skill_areas,key'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['key.regex' => 'A skill-area key is lowercase letters, digits and underscores.']);

        $saved = $this->runGuarded(function () use ($data): void {
            SkillArea::create([
                'key' => $data['key'],
                'name' => $data['name'],
                'description' => $data['description'] ?: null,
                'position' => (int) SkillArea::query()->max('position') + 1,
            ]);
        }, 'Skill area created.');

        if ($saved) {
            $this->creating = false;
        }
    }

    public function render(): View
    {
        return view('livewire.admin.skill-areas', [
            'skillAreas' => SkillArea::query()->orderBy('position')->get(),
        ])->layout('components.layouts.app', ['title' => 'Skill areas']);
    }
}
