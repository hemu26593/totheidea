<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Domain\Forms\AnswerValueMapper;
use App\Domain\Forms\QuestionVisibility;
use App\Domain\Forms\SubmissionService;
use App\Domain\Scoring\ScoringService;
use App\Enums\QuestionType;
use App\Livewire\Concerns\ReportsDomainFailures;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Question;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Renders ANY published form version, and records answers through the form
 * engine.
 *
 * NOTHING ABOUT ANY PARTICULAR FORM IS HARD-CODED. There is no mention of the
 * four intake instruments anywhere in this class: it reads sections,
 * questions, options and question types from the version the submission is
 * bound to, and renders whatever it finds. A form authored by hand and a form
 * drafted by AI reach this screen through exactly the same path.
 *
 * ANSWERS GO THROUGH SubmissionService, always. That service holds the two
 * invariants a foreign key cannot express - a question must belong to the
 * submission's own version, and a selected option must belong to its question
 * - so writing an answer row from here would be writing around the rules.
 *
 * SCORES ARE READ, NEVER WRITTEN. ScoringService is the sole authority for
 * submission_scores. This component displays what it computed and computes
 * nothing itself; in particular it shows no band or verdict, because no band
 * thresholds have been defined and the four categorical values have no
 * numeric mapping.
 */
class FormRenderer extends Component
{
    use ReportsDomainFailures;

    #[Locked]
    public int $submissionId;

    /** Scalar answers, keyed by question id. */
    public array $answers = [];

    /** Selected option ids, keyed by question id. */
    public array $selections = [];

    public function mount(FormSubmission $submission): void
    {
        $this->authorize('view', $submission);

        $this->submissionId = (int) $submission->getKey();

        $this->loadExistingAnswers($submission);
    }

    /**
     * Persist one question's answer.
     *
     * Saved question by question rather than as one form post: an intake is
     * long, and a participant who loses a session should not lose the whole
     * instrument with it.
     */
    public function saveAnswer(int $questionId, SubmissionService $submissions): void
    {
        $submission = $this->submission();

        $this->authorize('update', $submission);

        if (! $submission->isDraft()) {
            $this->addError('domain', 'This submission has been submitted and can no longer be edited.');

            return;
        }

        $question = $this->questionInVersion($submission, $questionId);

        $this->runGuarded(function () use ($submissions, $submission, $question): void {
            $mapper = app(AnswerValueMapper::class);

            $submissions->answer(
                $submission,
                $question,
                $mapper->scalarValue($question, $this->answers[$question->getKey()] ?? null),
                $mapper->selectedOptions($question, (array) ($this->selections[$question->getKey()] ?? [])),
            );

            // Changing a controlling answer can hide a conditional question
            // that already has one. The engine clears it, so a retracted
            // answer does not survive as though it had been given. A form with
            // no conditional questions does nothing here.
            $submissions->pruneHiddenAnswers($submission);
        }, 'Answer saved.');
    }

    public function submit(SubmissionService $submissions): void
    {
        $submission = $this->submission();

        $this->authorize('update', $submission);

        // Before the check, not after: a required question that is no longer
        // shown must not hold up a submission, and an answer to one that is
        // hidden must not travel with it.
        $submissions->pruneHiddenAnswers($submission);

        $missing = app(AnswerValueMapper::class)->unansweredRequiredQuestions($submission->fresh());

        if ($missing->isNotEmpty()) {
            $this->addError('domain', sprintf(
                '%d required question(s) are unanswered: %s.',
                $missing->count(),
                $missing->take(3)->pluck('label')->implode('; '),
            ));

            return;
        }

        $this->runGuarded(
            fn () => $submissions->submit($submission, auth()->user()),
            'Submission recorded.',
        );
    }

    /**
     * Ask ScoringService to score a submitted instrument.
     *
     * The button exists because scoring is an explicit act with a recorded
     * scheme version, not a side effect of submitting. This component passes
     * the submission and displays the result; every figure is the service's.
     */
    public function score(ScoringService $scoring): void
    {
        $submission = $this->submission();

        // Scoring is an assessment act, not a form edit, so it asks for its
        // own permission rather than riding on the submission's.
        if (! auth()->user()->can('assessments.manage')) {
            abort(403);
        }

        $this->runGuarded(
            fn () => $scoring->score($submission),
            'Scores computed.',
        );
    }

    public function render(): View
    {
        $submission = $this->submission();
        $version = $submission->formVersion;

        return view('livewire.forms.form-renderer', [
            'submission' => $submission,
            'version' => $version,
            'template' => $version?->formTemplate,
            'sections' => $version?->sections()->with(['questions.options'])->get() ?? collect(),
            'scores' => $submission->scores()->with('skillArea:id,name')->latest('computed_at')->get(),
            'questionTypes' => QuestionType::class,
            // Conditional questions whose condition is unmet. Presentation
            // only: nothing is authorized or validated by their absence.
            'hiddenQuestions' => $this->concealedQuestionIds($version),
        ])->layout('components.layouts.app', ['title' => $version?->formTemplate?->name ?? 'Form']);
    }

    /**
     * The conditional questions the current answers do not reveal.
     *
     * Read from the component's own selections rather than from the database,
     * so a choice toggles the dependent question in the same round trip that
     * saved it.
     *
     * NOT named hiddenQuestions(): a private helper sharing a name with a
     * public property is how a Livewire lifecycle hook gets registered by
     * accident.
     *
     * @return array<int, int>
     */
    private function concealedQuestionIds(?FormVersion $version): array
    {
        if ($version === null) {
            return [];
        }

        $visibility = app(QuestionVisibility::class);

        $selections = [];

        foreach ($this->selections as $questionId => $optionIds) {
            $selections[(int) $questionId] = array_map('intval', (array) $optionIds);
        }

        return $version->questions()
            ->whereNotNull('visible_when')
            ->get()
            ->reject(fn (Question $question): bool => $visibility->isVisible($question, $selections))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function submission(): FormSubmission
    {
        return FormSubmission::query()
            ->with(['formVersion.formTemplate', 'enrollment.customer'])
            ->findOrFail($this->submissionId);
    }

    /**
     * A question id arriving from the browser must belong to the version this
     * submission is bound to. SubmissionService asserts the same thing; doing
     * it here turns a mismatched id into a 404 rather than an exception.
     */
    private function questionInVersion(FormSubmission $submission, int $questionId): Question
    {
        $question = Question::query()->findOrFail($questionId);

        if ((int) $question->form_version_id !== (int) $submission->form_version_id) {
            abort(404);
        }

        return $question;
    }

    /**
     * Deliberately NOT named hydrateAnswers(). Livewire treats
     * hydrate{Property} as a lifecycle hook, so that name would be called on
     * every hydration of $answers - with no argument, and with Livewire trying
     * to implicitly resolve the parameter. Naming a helper after a property is
     * how you accidentally register a hook.
     */
    private function loadExistingAnswers(FormSubmission $submission): void
    {
        [$this->answers, $this->selections] = app(AnswerValueMapper::class)->existingAnswers($submission);
    }
}
