<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Enums\QuestionType;
use App\Models\Answer;
use App\Models\FormSubmission;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Collection;

/**
 * The translation between a question's TYPE and the shape its answer takes -
 * in the database, and in whatever control a person types into.
 *
 * This is not a second form engine. SubmissionService still owns every
 * invariant that matters (a question belongs to the submission's version, an
 * option belongs to its question) and remains the only thing that writes an
 * answer row. What lives here is the type mapping that both renderers need and
 * that would otherwise exist twice: once for the internal Livewire screen and
 * once for the external one. Two copies of "which column does a boolean go
 * in?" is exactly the duplicated business rule the architecture forbids.
 *
 * READING IS BY TYPE, NEVER BY "whichever column is not null". A recorded
 * `false` and a recorded `0` are real answers. A form that renders a recorded
 * No as an empty dash shows a consultant something the participant did not
 * say, which is worse than showing nothing because it looks like an answer.
 */
class AnswerValueMapper
{
    /**
     * Read a stored answer back into the shape its control expects.
     *
     * Strings, because that is what an HTML control round-trips: a PHP `true`
     * never matches an <option value="1">.
     */
    public function displayValue(Answer $answer): string|int|float|null
    {
        return match ($answer->question?->type) {
            QuestionType::Boolean => $answer->value_boolean === null
                ? null
                : ($answer->value_boolean ? '1' : '0'),

            // decimal:4 casts to "18.0000"; the trailing scale is storage
            // detail and does not belong in the box the user reads.
            QuestionType::Number, QuestionType::Scale => $answer->value_number === null
                ? null
                : 0 + $answer->value_number,

            QuestionType::Date => $answer->value_date?->toDateString(),

            // A choice question keeps its answer in the selections map.
            QuestionType::SelectOne, QuestionType::SelectMany => null,

            default => $answer->value_text,
        };
    }

    /**
     * Every answer already recorded against a submission, as
     * [scalars keyed by question id, selected option ids keyed by question id].
     *
     * @return array{0: array<int, string|int|float|null>, 1: array<int, array<int, int>>}
     */
    public function existingAnswers(FormSubmission $submission): array
    {
        $scalars = [];
        $selections = [];

        foreach ($submission->answers()->with(['answerOptions', 'question'])->get() as $answer) {
            $scalars[$answer->question_id] = $this->displayValue($answer);

            $selections[$answer->question_id] = $answer->answerOptions
                ->pluck('question_option_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return [$scalars, $selections];
    }

    /**
     * Map one submitted input onto the column the question's type uses.
     *
     * @return array<string, mixed>
     */
    public function scalarValue(Question $question, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_boolean' => null];
        }

        return match ($question->type) {
            QuestionType::Number, QuestionType::Scale => ['value_number' => (float) $raw],
            QuestionType::Date => ['value_date' => (string) $raw],
            QuestionType::Boolean => ['value_boolean' => (bool) $raw],
            QuestionType::SelectOne, QuestionType::SelectMany => [],
            default => ['value_text' => (string) $raw],
        };
    }

    /**
     * Resolve submitted option ids to this question's OWN options.
     *
     * Constrained in the query, so a foreign id simply finds nothing rather
     * than reaching the service as a rejected argument. SubmissionService
     * asserts the same thing; both are cheap and the outer one keeps a
     * tampered id from ever becoming an exception.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, QuestionOption>
     */
    public function selectedOptions(Question $question, array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return [];
        }

        $options = QuestionOption::query()
            ->where('question_id', $question->getKey())
            ->whereIn('id', $ids)
            ->get();

        // One choice means one choice, whatever the request contained.
        if ($question->type === QuestionType::SelectOne) {
            $options = $options->take(1);
        }

        return $options->all();
    }

    /**
     * The required questions this submission has not answered.
     *
     * @return Collection<int, Question>
     */
    public function unansweredRequiredQuestions(FormSubmission $submission): Collection
    {
        $answered = $submission->answers()->get()->keyBy('question_id');

        return $submission->formVersion
            ->questions()
            ->where('is_required', true)
            ->get()
            ->filter(function (Question $question) use ($answered): bool {
                $answer = $answered[$question->getKey()] ?? null;

                if ($answer === null) {
                    return true;
                }

                if (in_array($question->type, [QuestionType::SelectOne, QuestionType::SelectMany], true)) {
                    return $answer->answerOptions()->count() === 0;
                }

                return $answer->value_text === null
                    && $answer->value_number === null
                    && $answer->value_date === null
                    && $answer->value_boolean === null;
            });
    }
}
