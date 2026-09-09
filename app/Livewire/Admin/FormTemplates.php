<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Forms\FormBuilderService;
use App\Domain\Forms\FormPublishingService;
use App\Enums\QuestionType;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Question;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Authoring form templates and their versions.
 *
 * A PUBLISHED VERSION IS NEVER EDITED. The only way a live form changes is a
 * new draft version, published in its turn - which is what keeps every
 * submission already bound to the old version meaningful. There is no edit
 * control on a published version anywhere on this screen.
 *
 * SCORE VALUES ARE NOT AUTHORED HERE. FormBuilderService refuses a numeric
 * score on any of the four categorical labels, because that mapping is an open
 * client decision. Options are added without one.
 */
class FormTemplates extends Component
{
    use ReportsDomainFailures;

    #[Url]
    public ?int $templateId = null;

    #[Url]
    public ?int $versionId = null;

    public bool $creatingTemplate = false;

    public string $name = '';

    public string $key = '';

    public bool $isScored = false;

    public bool $addingSection = false;

    public string $sectionTitle = '';

    public ?int $addingQuestionTo = null;

    public string $questionLabel = '';

    public string $questionType = 'text';

    public bool $questionRequired = false;

    public function mount(): void
    {
        $this->authorize('viewAny', FormTemplate::class);
    }

    public function startTemplate(): void
    {
        $this->authorize('create', FormTemplate::class);

        $this->reset(['name', 'key', 'isScored']);
        $this->creatingTemplate = true;
    }

    public function createTemplate(FormBuilderService $builder): void
    {
        $this->authorize('create', FormTemplate::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'isScored' => ['boolean'],
        ], ['key.regex' => 'A form key is lowercase letters, digits and underscores.']);

        $saved = $this->runGuarded(function () use ($builder, $data): void {
            $template = $builder->createTemplate([
                // Shared curriculum. A customer-specific template is created
                // from that customer's own workspace.
                'customer_id' => null,
                'key' => $data['key'],
                'name' => $data['name'],
                'is_scored' => $data['isScored'],
            ]);

            $this->templateId = (int) $template->getKey();
        }, 'Template created.');

        if ($saved) {
            $this->creatingTemplate = false;
        }
    }

    public function startVersion(FormPublishingService $publishing): void
    {
        $template = $this->template();

        $this->authorize('create', FormVersion::class);

        $this->runGuarded(function () use ($publishing, $template): void {
            $this->versionId = (int) $publishing->startNextVersion($template)->getKey();
        }, 'Draft version started.');
    }

    public function startSection(): void
    {
        $this->authorize('update', $this->version());

        $this->sectionTitle = '';
        $this->addingSection = true;
    }

    public function addSection(FormBuilderService $builder): void
    {
        $version = $this->version();

        $this->authorize('update', $version);

        $this->validate(['sectionTitle' => ['required', 'string', 'max:150']]);

        $saved = $this->runGuarded(function () use ($builder, $version): void {
            $builder->addSection($version, $this->sectionTitle, $version->sections()->count());
        }, 'Section added.');

        if ($saved) {
            $this->addingSection = false;
        }
    }

    public function startQuestion(int $sectionId): void
    {
        $this->authorize('update', $this->version());

        $this->addingQuestionTo = $sectionId;
        $this->questionLabel = '';
        $this->questionType = 'text';
        $this->questionRequired = false;
    }

    public function addQuestion(FormBuilderService $builder): void
    {
        $version = $this->version();

        $this->authorize('update', $version);

        $this->validate([
            'questionLabel' => ['required', 'string', 'max:500'],
            'questionType' => ['required', 'string', 'in:'.implode(',', QuestionType::values())],
        ]);

        $section = FormSection::query()->findOrFail($this->addingQuestionTo);

        // A question's section must belong to the SAME version. The builder
        // asserts it; checking here makes a stale page a 404 rather than a 500.
        if ((int) $section->form_version_id !== (int) $version->getKey()) {
            abort(404);
        }

        $saved = $this->runGuarded(function () use ($builder, $version, $section): void {
            $question = $builder->addQuestion(
                $version,
                $section,
                QuestionType::from($this->questionType),
                $this->questionLabel,
                Question::query()->where('form_version_id', $version->getKey())->count(),
                ['is_required' => $this->questionRequired],
            );

            // Choice questions get the categorical set, added deliberately
            // WITHOUT numeric scores - the mapping is an open client decision.
            if (in_array($question->type, [QuestionType::SelectOne, QuestionType::SelectMany], true)) {
                $builder->addScoringCategoryOptions($question);
            }
        }, 'Question added.');

        if ($saved) {
            $this->addingQuestionTo = null;
        }
    }

    public function publish(FormPublishingService $publishing): void
    {
        $version = $this->version();

        $this->authorize('publish', $version);

        $this->runGuarded(
            fn () => $publishing->publish($version, auth()->user()),
            'Version published. It is now immutable and answerable.',
        );
    }

    public function render(): View
    {
        $template = $this->templateId ? FormTemplate::query()->find($this->templateId) : null;
        $version = $this->versionId ? FormVersion::query()->find($this->versionId) : null;

        return view('livewire.admin.form-templates', [
            'templates' => FormTemplate::query()->withCount('versions')->orderBy('name')->get(),
            'template' => $template,
            'versions' => $template?->versions()->orderByDesc('version_number')->get() ?? collect(),
            'version' => $version,
            'sections' => $version?->sections()->with('questions')->get() ?? collect(),
            'questionTypes' => QuestionType::cases(),
        ])->layout('components.layouts.app', ['title' => 'Form templates']);
    }

    private function template(): FormTemplate
    {
        return FormTemplate::query()->findOrFail($this->templateId);
    }

    private function version(): FormVersion
    {
        return FormVersion::query()->findOrFail($this->versionId);
    }
}
