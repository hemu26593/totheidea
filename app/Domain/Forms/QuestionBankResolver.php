<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Models\Answer;
use App\Models\Enrollment;
use App\Models\Question;

/**
 * The "repeated questions asked only once" rule (SOW module 2).
 *
 * Two of the client's documents overlap, so the same logical question appears
 * on more than one template. bank_key identifies it across them, and this
 * resolver finds what the participant already answered so the form can be
 * pre-filled rather than asked again.
 *
 * It reads through the ANSWER's own question, never through "the current
 * version", so a prior answer keeps the meaning it had when it was given.
 */
class QuestionBankResolver
{
    /**
     * The most recent answer this enrolment gave to any question sharing the
     * given bank key.
     */
    public function priorAnswer(Enrollment $enrollment, string $bankKey): ?Answer
    {
        return Answer::query()
            ->whereHas('question', fn ($q) => $q->where('bank_key', $bankKey))
            ->whereHas('formSubmission', fn ($q) => $q->where('enrollment_id', $enrollment->getKey()))
            ->latest('id')
            ->first();
    }

    public function hasAnswered(Enrollment $enrollment, string $bankKey): bool
    {
        return $this->priorAnswer($enrollment, $bankKey) !== null;
    }

    /**
     * Questions in a version that this enrolment has already answered
     * elsewhere, keyed by question id.
     *
     * @return array<int, Answer>
     */
    public function prefillFor(Enrollment $enrollment, int $formVersionId): array
    {
        $prefill = [];

        $questions = Question::query()
            ->where('form_version_id', $formVersionId)
            ->whereNotNull('bank_key')
            ->get();

        foreach ($questions as $question) {
            $prior = $this->priorAnswer($enrollment, (string) $question->bank_key);

            if ($prior !== null && (int) $prior->question_id !== (int) $question->getKey()) {
                $prefill[(int) $question->getKey()] = $prior;
            }
        }

        return $prefill;
    }
}
