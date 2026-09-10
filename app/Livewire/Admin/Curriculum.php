<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Sessions\CurriculumService;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\AssignmentTemplate;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\SessionTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The curriculum: sessions 1-6, the forms attached to each, and the
 * assignments each sets.
 *
 * SESSIONS 1-6 ONLY. The sequence field is constrained to that range because
 * that is the delivered programme; sessions 7-18 are not part of this build
 * and are not creatable here.
 *
 * Authoring goes through CurriculumService, which holds the uniqueness of a
 * sequence within a programme and the archive rules.
 */
class Curriculum extends Component
{
    use ReportsDomainFailures;

    /** The delivered programme. */
    public const MAX_SEQUENCE = 6;

    #[Url]
    public ?int $programId = null;

    public bool $definingSession = false;

    public int $sequence = 1;

    public string $title = '';

    public string $theme = '';

    public string $objectives = '';

    public ?int $attachingToSessionId = null;

    public ?int $formTemplateId = null;

    public bool $formRequired = true;

    public ?int $definingAssignmentFor = null;

    public string $assignmentTitle = '';

    public string $assignmentInstructions = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SessionTemplate::class);

        $this->programId ??= Program::query()->orderBy('name')->value('id');
    }

    public function startSession(): void
    {
        $this->authorize('create', SessionTemplate::class);

        $this->reset(['title', 'theme', 'objectives']);
        $this->sequence = $this->nextSequence();
        $this->definingSession = true;
    }

    public function defineSession(CurriculumService $curriculum): void
    {
        $this->authorize('create', SessionTemplate::class);

        $this->validate([
            'programId' => ['required', 'integer', 'exists:programs,id'],
            'sequence' => ['required', 'integer', 'between:1,'.self::MAX_SEQUENCE],
            'title' => ['required', 'string', 'max:150'],
            'theme' => ['nullable', 'string', 'max:150'],
            'objectives' => ['nullable', 'string', 'max:5000'],
        ]);

        $program = Program::query()->findOrFail($this->programId);

        $saved = $this->runGuarded(function () use ($curriculum, $program): void {
            $curriculum->defineSession(
                $program,
                $this->sequence,
                $this->title,
                $this->theme ?: null,
                $this->objectives ?: null,
                auth()->user(),
            );
        }, 'Session defined.');

        if ($saved) {
            $this->definingSession = false;
        }
    }

    public function startFormAttachment(int $sessionTemplateId): void
    {
        $this->authorize('create', SessionTemplate::class);

        $this->attachingToSessionId = $sessionTemplateId;
        $this->formTemplateId = null;
        $this->formRequired = true;
    }

    public function attachForm(CurriculumService $curriculum): void
    {
        $this->authorize('create', SessionTemplate::class);

        $this->validate([
            'attachingToSessionId' => ['required', 'integer'],
            'formTemplateId' => ['required', 'integer', 'exists:form_templates,id'],
        ]);

        $session = $this->sessionInProgram((int) $this->attachingToSessionId);
        $form = $this->sharedFormTemplate((int) $this->formTemplateId);

        $saved = $this->runGuarded(
            fn () => $curriculum->attachForm($session, $form, $this->formRequired, 0, auth()->user()),
            'Form attached.',
        );

        if ($saved) {
            $this->attachingToSessionId = null;
        }
    }

    public function startAssignment(int $sessionTemplateId): void
    {
        $this->authorize('create', AssignmentTemplate::class);

        $this->definingAssignmentFor = $sessionTemplateId;
        $this->assignmentTitle = '';
        $this->assignmentInstructions = '';
    }

    public function defineAssignment(CurriculumService $curriculum): void
    {
        $this->authorize('create', AssignmentTemplate::class);

        $this->validate([
            'definingAssignmentFor' => ['required', 'integer'],
            'assignmentTitle' => ['required', 'string', 'max:200'],
            'assignmentInstructions' => ['nullable', 'string', 'max:5000'],
        ]);

        $session = $this->sessionInProgram((int) $this->definingAssignmentFor);

        $saved = $this->runGuarded(
            fn () => $curriculum->defineAssignment(
                $session,
                $this->assignmentTitle,
                $this->assignmentInstructions ?: null,
                actor: auth()->user(),
            ),
            'Assignment defined.',
        );

        if ($saved) {
            $this->definingAssignmentFor = null;
        }
    }

    public function render(): View
    {
        $program = $this->programId ? Program::query()->find($this->programId) : null;

        return view('livewire.admin.curriculum', [
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'program' => $program,
            'sessions' => $program === null
                ? collect()
                : SessionTemplate::query()
                    ->where('program_id', $program->getKey())
                    ->whereNull('archived_at')
                    ->with(['templateForms.formTemplate', 'assignmentTemplates'])
                    ->orderBy('sequence')
                    ->get(),
            'formTemplates' => FormTemplate::query()->whereNull('customer_id')->orderBy('name')->get(),
            'maxSequence' => self::MAX_SEQUENCE,
            'curriculumComplete' => $program !== null && app(CurriculumService::class)->curriculumIsComplete($program),
        ])->layout('components.layouts.app', ['title' => 'Curriculum']);
    }

    private function nextSequence(): int
    {
        $used = SessionTemplate::query()
            ->where('program_id', $this->programId)
            ->whereNull('archived_at')
            ->pluck('sequence')
            ->all();

        for ($i = 1; $i <= self::MAX_SEQUENCE; $i++) {
            if (! in_array($i, $used, true)) {
                return $i;
            }
        }

        return self::MAX_SEQUENCE;
    }

    /**
     * Only SHARED curriculum may be attached to a programme.
     *
     * A form template carrying a customer_id is that business's own
     * instrument. Attaching one here would put it into the curriculum every
     * batch runs, so one customer's bespoke questions would be asked of
     * everybody. The picker offers shared templates only; this refuses the
     * request that did not come from the picker.
     */
    private function sharedFormTemplate(int $formTemplateId): FormTemplate
    {
        $form = FormTemplate::query()->findOrFail($formTemplateId);

        if ($form->customer_id !== null) {
            abort(404);
        }

        return $form;
    }

    /**
     * A session template id from the browser must belong to the programme
     * currently selected.
     */
    private function sessionInProgram(int $sessionTemplateId): SessionTemplate
    {
        $session = SessionTemplate::query()->findOrFail($sessionTemplateId);

        if ((int) $session->program_id !== (int) $this->programId) {
            abort(404);
        }

        return $session;
    }
}
