<?php

declare(strict_types=1);

namespace App\Livewire\Ai;

use App\Domain\Ai\PromptVersionService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AiPromptVersion;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Prompt versions: what the model is told to do, and when it changed.
 *
 * A PUBLISHED PROMPT IS IMMUTABLE, so there is no edit control for one. The
 * only way a prompt changes is a new version, which is what keeps every
 * generation already pointing at it explicable.
 *
 * The template is shown in full because it is system vocabulary and contains
 * no customer data - placeholders only. That is a rule the model enforces, not
 * a convention this screen relies on.
 */
class Prompts extends Component
{
    use ReportsDomainFailures;

    public bool $drafting = false;

    public string $key = '';

    public string $template = '';

    public string $modelIdentifier = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AiPromptVersion::class);
    }

    public function startDraft(): void
    {
        $this->authorize('create', AiPromptVersion::class);

        $this->reset(['key', 'template', 'modelIdentifier']);
        $this->drafting = true;
    }

    public function saveDraft(PromptVersionService $prompts): void
    {
        $this->authorize('create', AiPromptVersion::class);

        $this->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'template' => ['required', 'string', 'max:20000'],
            'modelIdentifier' => ['nullable', 'string', 'max:100'],
        ], [
            'key.regex' => 'A prompt key is lowercase letters, digits and underscores.',
        ]);

        $saved = $this->runGuarded(function () use ($prompts): void {
            $prompts->createDraft(
                $this->key,
                $this->template,
                auth()->user(),
                null,
                $this->modelIdentifier !== '' ? $this->modelIdentifier : null,
            );
        }, 'Draft prompt version created.');

        if ($saved) {
            $this->drafting = false;
        }
    }

    public function publish(int $promptId, PromptVersionService $prompts): void
    {
        $prompt = AiPromptVersion::query()->findOrFail($promptId);

        $this->authorize('publish', $prompt);

        $this->runGuarded(
            fn () => $prompts->publish($prompt, auth()->user()),
            'Prompt version published. It is now immutable.',
        );
    }

    public function render(): View
    {
        return view('livewire.ai.prompts', [
            'prompts' => AiPromptVersion::query()
                ->with('createdBy:id,name')
                ->withCount('generations')
                ->orderBy('key')
                ->orderByDesc('version_number')
                ->get()
                ->groupBy('key'),
        ])->layout('components.layouts.app', ['title' => 'AI prompt versions']);
    }
}
