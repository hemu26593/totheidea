<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Models\Answer;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\SkillArea;
use App\Models\SubmissionScore;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the model's context AT CALL TIME, for one customer.
 *
 * WHY THERE IS NO customer_ai_data TABLE. A staging table for "what the AI
 * should know" would become a second source of truth, and a stale one. Worse,
 * a leak from a dumping table is a silent data bug you find months later,
 * whereas a leak here would have to be a query written without its
 * customer_id - which is a thing a reviewer can see and a test can catch.
 *
 * EVERY QUERY IN THIS CLASS IS SCOPED BY customer_id, and the id comes from
 * the Customer model passed in, never from a request. There is no method that
 * takes a list of customers, and no branch that widens the scope, so there is
 * no code path here that reads across businesses.
 *
 * CONTEXT MINIMISATION IS A RULE, NOT AN OPTIMISATION. The model is given the
 * smallest set of facts that can answer the question. Names of individuals,
 * contact details, payment terms and internal notes are not selected, because
 * none of them helps and each is one more thing that has left the building.
 * The ceilings in config('ai.context') stop a selection growing unnoticed as
 * the data does.
 *
 * FIGURES ARE READING MATERIAL. Scores are read as the scoring domain already
 * computed them and are passed through unchanged. Nothing here asks the model
 * to total, average, band or rank anything.
 */
class CustomerContextAssembler
{
    /**
     * A general profile: who this business is and where it is in the
     * programme. Enough to write about them; nothing about anybody else.
     */
    public function forCustomer(Customer $customer): CustomerContext
    {
        $customerId = (int) $customer->getKey();

        return new CustomerContext(
            customerId: $customerId,
            facts: [
                'customer' => [
                    'id' => $customerId,
                    'name' => $customer->name,
                    'status' => (string) $customer->status,
                ],
                'enrollments' => $this->enrollmentFacts($customerId),
            ],
        );
    }

    /**
     * Context for drafting a form.
     *
     * A form is a structure, so the useful context is the SHAPE of what this
     * business already answers - skill areas and instrument names - not the
     * answers themselves. The purpose statement is the only free text, and it
     * is supplied by a member of staff rather than by a participant.
     */
    public function forFormDraft(Customer $customer, string $purposeStatement): CustomerContext
    {
        $customerId = (int) $customer->getKey();

        return new CustomerContext(
            customerId: $customerId,
            facts: [
                'customer' => [
                    'id' => $customerId,
                    'name' => $customer->name,
                ],
                'brief' => $this->truncate($purposeStatement),
                'skill_areas' => $this->skillAreaNames(),
            ],
        );
    }

    /**
     * Context for a report narrative.
     *
     * The figures are the ones the deterministic report builders already
     * produced, handed over verbatim. That is deliberate: the narrative
     * describes the same numbers the reader sees in the tables, and the model
     * is given no opportunity to derive a different one.
     *
     * @param  array<string, mixed>  $reportFacts
     */
    public function forNarrative(Customer $customer, array $reportFacts): CustomerContext
    {
        $customerId = (int) $customer->getKey();

        return new CustomerContext(
            customerId: $customerId,
            facts: [
                'customer' => [
                    'id' => $customerId,
                    'name' => $customer->name,
                ],
                'report' => $reportFacts,
                'scores' => $this->scoreFacts($customerId),
            ],
            customerText: $this->customerAuthoredText($customerId),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function enrollmentFacts(int $customerId): array
    {
        return Enrollment::query()
            ->where('customer_id', $customerId)
            ->with('batch:id,name,starts_on')
            ->latest('enrolled_at')
            ->limit((int) config('ai.context.max_enrollments', 5))
            ->get()
            ->map(fn (Enrollment $enrollment): array => [
                'batch' => $enrollment->batch?->name,
                'status' => (string) $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * Scores exactly as the scoring domain already computed them.
     *
     * Joined through form_submissions.customer_id - the denormalised column
     * that exists precisely so a scoped read cannot be defeated by a missed
     * join. Band names are absent because no band thresholds have been
     * defined; inventing one here would be inventing a scoring rule.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scoreFacts(int $customerId): array
    {
        return SubmissionScore::query()
            ->whereIn(
                'form_submission_id',
                FormSubmission::query()->where('customer_id', $customerId)->select('id'),
            )
            ->with('skillArea:id,name')
            ->latest('computed_at')
            ->limit((int) config('ai.context.max_score_rows', 40))
            ->get()
            ->map(fn (SubmissionScore $score): array => [
                'skill_area' => $score->skillArea?->name,
                'score_type' => (string) $score->score_type,
                'raw_score' => (float) $score->raw_score,
                'max_score' => (float) $score->max_score,
                'percentage' => $score->percentage === null ? null : (float) $score->percentage,
                'scheme_version' => (string) $score->scheme_version,
            ])
            ->all();
    }

    /**
     * The instrument vocabulary, which is programme-wide and not customer data.
     *
     * @return array<int, string>
     */
    private function skillAreaNames(): array
    {
        return SkillArea::query()
            ->whereNull('archived_at')
            ->orderBy('position')
            ->pluck('name')
            ->map(static fn (string $name): string => $name)
            ->all();
    }

    /**
     * Free text this business wrote, carried across as clearly-marked data.
     *
     * Only free-text ANSWERS are taken, and only from this customer's own
     * submissions - scoped through form_submissions.customer_id, the
     * denormalised column that exists precisely so a scoped read cannot be
     * defeated by a missed join.
     *
     * This is the material the untrusted-data rule is about. A participant
     * can type anything into a form field, including something shaped like an
     * instruction, and CustomerContext::render() fences it as quoted
     * third-party text before it reaches the model.
     *
     * @return array<int, array{label: string, text: string}>
     */
    private function customerAuthoredText(int $customerId): array
    {
        /** @var Collection<int, Answer> $answers */
        $answers = Answer::query()
            ->whereIn(
                'form_submission_id',
                FormSubmission::query()->where('customer_id', $customerId)->select('id'),
            )
            ->whereNotNull('value_text')
            ->with('question:id,label')
            ->latest('id')
            ->limit((int) config('ai.context.max_free_text_answers', 10))
            ->get();

        return $answers
            ->map(fn (Answer $answer): array => [
                'label' => (string) ($answer->question?->label ?? 'answer'),
                'text' => $this->truncate((string) $answer->value_text),
            ])
            ->values()
            ->all();
    }

    private function truncate(string $text): string
    {
        $limit = (int) config('ai.context.max_text_length', 2000);

        return mb_strlen($text) <= $limit
            ? $text
            : mb_substr($text, 0, $limit).' […truncated]';
    }
}
