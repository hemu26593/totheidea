<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Answer;
use App\Models\AnswerOption;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The submission lifecycle: start a draft, answer incrementally, submit,
 * amend.
 *
 * Three rules live here, none of which a database constraint can express:
 *
 *   1. A submission binds to the EXACT version it was presented, and the
 *      binding is never recomputed afterwards.
 *   2. An answer's question must belong to that version. Otherwise a
 *      submission could accumulate answers to questions nobody was shown.
 *   3. The redundant customer_id must equal the enrolment's customer, set on
 *      insert and never changed.
 *
 * Drafts are written incrementally, which is what "saved half-done and
 * finished later" means in the SOW.
 */
class SubmissionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Start a draft against an explicit version.
     *
     * The caller passes the version deliberately - there is no overload that
     * resolves "the current version" later, because that is precisely the
     * mistake this design prevents.
     */
    public function startDraft(
        Enrollment $enrollment,
        FormVersion $version,
        ?User $actor = null,
        ?AccessGrant $grant = null,
    ): FormSubmission {
        $this->assertVersionIsAnswerable($version);
        $source = $this->resolveSource($actor, $grant);

        if ($grant !== null && (int) $grant->enrollment_id !== (int) $enrollment->getKey()) {
            throw CustomerIsolationException::mismatch(
                'grant '.$grant->getKey(),
                'enrolment '.$enrollment->getKey(),
                'enrolment '.$grant->enrollment_id,
            );
        }

        return DB::transaction(function () use ($enrollment, $version, $actor, $grant, $source): FormSubmission {
            $submission = new FormSubmission;
            $submission->forceFill([
                'form_version_id' => $version->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                // Redundant copy, taken from the enrolment - never from a
                // request.
                'customer_id' => $enrollment->customer_id,
                'status' => 'draft',
                'source' => $source,
                'created_by' => $actor?->getKey(),
                'access_grant_id' => $grant?->getKey(),
            ])->save();

            return $submission->fresh();
        });
    }

    /**
     * Record or replace the answer to one question.
     *
     * @param  array<string, mixed>  $value  one of value_text/number/date/boolean
     * @param  array<int, QuestionOption>  $options
     */
    public function answer(
        FormSubmission $submission,
        Question $question,
        array $value = [],
        array $options = [],
    ): Answer {
        $this->assertQuestionBelongsToSubmissionVersion($question, $submission);

        foreach ($options as $option) {
            $this->assertOptionBelongsToQuestion($option, $question);
        }

        return DB::transaction(function () use ($submission, $question, $value, $options): Answer {
            $answer = Answer::query()->firstOrNew([
                'form_submission_id' => $submission->getKey(),
                'question_id' => $question->getKey(),
            ]);

            $answer->fill($value);
            $answer->forceFill([
                'form_submission_id' => $submission->getKey(),
                'question_id' => $question->getKey(),
            ])->save();

            // Replace the selection wholesale: a partial update would leave
            // a stale choice behind and change what the participant said.
            $answer->answerOptions()->delete();

            foreach ($options as $option) {
                $selection = new AnswerOption;
                $selection->forceFill([
                    'answer_id' => $answer->getKey(),
                    'question_option_id' => $option->getKey(),
                ])->save();
            }

            return $answer->fresh();
        });
    }

    public function submit(FormSubmission $submission, ?User $actor = null, ?AccessGrant $grant = null): FormSubmission
    {
        return DB::transaction(function () use ($submission, $actor, $grant): FormSubmission {
            $submission->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
            ])->save();

            $this->audit->log(
                AuditAction::SubmissionSubmitted,
                $submission,
                ['status' => 'draft'],
                ['status' => 'submitted', 'form_version_id' => $submission->form_version_id],
                $actor,
                $grant !== null ? ActorSource::ExternalGrant : null,
                $grant,
            );

            return $submission->fresh();
        });
    }

    public function amend(FormSubmission $submission, User $actor, string $reason): FormSubmission
    {
        return DB::transaction(function () use ($submission, $actor, $reason): FormSubmission {
            $submission->forceFill(['amended_at' => now()])->save();

            $this->audit->log(
                AuditAction::SubmissionAmended,
                $submission,
                null,
                ['reason' => $reason],
                $actor,
            );

            return $submission->fresh();
        });
    }

    /**
     * Guard against a caller pairing a submission with another customer.
     */
    public function assertBelongsToCustomer(FormSubmission $submission, int $customerId): void
    {
        if ((int) $submission->customer_id !== $customerId) {
            throw CustomerIsolationException::mismatch(
                'submission '.$submission->getKey(),
                'customer '.$customerId,
                'customer '.$submission->customer_id,
            );
        }
    }

    private function assertVersionIsAnswerable(FormVersion $version): void
    {
        if (! $version->isPublished()) {
            throw new RuntimeException(
                "Form version {$version->getKey()} is {$version->status} and cannot be answered."
            );
        }
    }

    /**
     * The rule that keeps a submission internally coherent.
     */
    private function assertQuestionBelongsToSubmissionVersion(Question $question, FormSubmission $submission): void
    {
        if ((int) $question->form_version_id !== (int) $submission->form_version_id) {
            throw new InvalidArgumentException(sprintf(
                'Question %d belongs to form version %d, but this submission is bound to version %d.',
                $question->getKey(),
                $question->form_version_id,
                $submission->form_version_id,
            ));
        }
    }

    private function assertOptionBelongsToQuestion(QuestionOption $option, Question $question): void
    {
        if ((int) $option->question_id !== (int) $question->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Option %d belongs to question %d, not %d.',
                $option->getKey(),
                $option->question_id,
                $question->getKey(),
            ));
        }
    }

    private function resolveSource(?User $actor, ?AccessGrant $grant): ActorSource
    {
        return match (true) {
            $grant !== null => ActorSource::ExternalGrant,
            $actor !== null => ActorSource::InternalUser,
            default => ActorSource::System,
        };
    }
}
