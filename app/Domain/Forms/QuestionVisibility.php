<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Models\Answer;
use App\Models\FormSubmission;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Collection;
use JsonException;

/**
 * Conditional questions: the rule in `questions.visible_when`, and the one
 * place it is read.
 *
 * The column has existed since the form engine was built, with no evaluator
 * behind it. This is the evaluator. It exists because the Participant Business
 * Analysis Questionnaire asks which platform performs best and then, only if
 * the answer is Other, asks which platform that is - a question that must not
 * be shown to everybody and must not be a required answer nobody can give.
 *
 * THE RULE IS DELIBERATELY TINY: one controlling question, and the option
 * values that reveal this one. It is not a conditional language, and it is not
 * executed - it is decoded, compared, and nothing else. Anything richer would
 * be an expression evaluator reading database content, which is the thing the
 * architecture refuses outright.
 *
 * IDS ARE SAFE TO STORE HERE because a question lives inside an immutable form
 * version: once published, neither the controlling question nor its options
 * can be renumbered or rewritten. A rule written at authoring time therefore
 * means the same thing for as long as the version is answerable.
 *
 * A MALFORMED OR DANGLING RULE SHOWS THE QUESTION. Hiding it would silently
 * drop a question from an instrument somebody is being assessed on, which is
 * worse than showing one question too many.
 */
final class QuestionVisibility
{
    /**
     * Build the rule for "show this only when $controlling has one of these
     * option values selected".
     *
     * Values, not option ids, because the seeder knows the values it wrote and
     * the ids are database detail.
     *
     * @throws JsonException
     */
    public static function whenSelected(Question $controlling, string ...$optionValues): string
    {
        return json_encode([
            'question_id' => (int) $controlling->getKey(),
            'option_values' => array_values($optionValues),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The decoded rule, or null when this question is unconditional.
     *
     * @return array{question_id: int, option_values: list<string>}|null
     */
    public function rule(Question $question): ?array
    {
        $raw = $question->visible_when;

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['question_id'], $decoded['option_values'])) {
            return null;
        }

        if (! is_array($decoded['option_values'])) {
            return null;
        }

        return [
            'question_id' => (int) $decoded['question_id'],
            'option_values' => array_values(array_map('strval', $decoded['option_values'])),
        ];
    }

    public function isConditional(Question $question): bool
    {
        return $this->rule($question) !== null;
    }

    /**
     * What a submission currently has selected, in the shape every visibility
     * decision is made against: option ids keyed by question id.
     *
     * It lives here rather than in each caller because a second copy of it
     * would be a second answer to "what counts as selected".
     *
     * @return array<int, array<int, int>>
     */
    public function selectionsFor(FormSubmission $submission): array
    {
        return $this->selectionsFrom($submission->answers()->with('answerOptions')->get());
    }

    /**
     * The same map, from answers that are already loaded.
     *
     * @param  Collection<int, Answer>  $answers
     * @return array<int, array<int, int>>
     */
    public function selectionsFrom(Collection $answers): array
    {
        $selections = [];

        foreach ($answers as $answer) {
            $selections[(int) $answer->question_id] = $answer->answerOptions
                ->pluck('question_option_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $selections;
    }

    /**
     * Whether this question should be shown, given what has been selected.
     *
     * @param  array<int, array<int, int>>  $selections  option ids, keyed by question id
     */
    public function isVisible(Question $question, array $selections): bool
    {
        $rule = $this->rule($question);

        if ($rule === null) {
            return true;
        }

        $chosen = array_map('intval', (array) ($selections[$rule['question_id']] ?? []));

        if ($chosen === []) {
            return false;
        }

        $values = QuestionOption::query()
            ->where('question_id', $rule['question_id'])
            ->whereIn('id', $chosen)
            ->pluck('value')
            ->all();

        return array_intersect($values, $rule['option_values']) !== [];
    }

    /**
     * The option ids that reveal this question, so a renderer can decide
     * without asking the server again.
     *
     * @return array{question_id: int, option_ids: list<int>}|null
     */
    public function revealedBy(Question $question): ?array
    {
        $rule = $this->rule($question);

        if ($rule === null) {
            return null;
        }

        return [
            'question_id' => $rule['question_id'],
            'option_ids' => QuestionOption::query()
                ->where('question_id', $rule['question_id'])
                ->whereIn('value', $rule['option_values'])
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        ];
    }
}
