<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Models\Answer;
use App\Models\FormSubmission;
use App\Models\SubmissionScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONLY writer of submission_scores.
 *
 * Laravel is authoritative for every number that reaches a report. AI may
 * interpret and narrate; it never computes a score. SubmissionScore refuses
 * writes from anywhere but this service.
 *
 * APPEND-ONLY: recomputation adds a new row and leaves the delivered one
 * intact, because the diagnostic report is handed over before Session 1 and a
 * later rule correction must not rewrite what was given.
 *
 * WHAT IS IMPLEMENTED - and only what is defined:
 *
 *   1. Overall assessment score, "out of 35" for the 35-question instrument.
 *      raw  = sum of selected options' score_value, plus numeric answers
 *      max  = sum of the version's questions' max_score
 *      For the 35-question assessment those sum to 35 by construction, which
 *      is what "scored out of 35" means.
 *
 *   2. The 18-question check: count the Yes answers.
 *      raw  = number of true boolean answers
 *      max  = number of boolean questions in the version
 *      The 10-or-more rule is exposed as a derived predicate. The SOW states
 *      the threshold but NOT what follows from crossing it, so no consequence
 *      is invented and no column stores one.
 *
 *   3. Per-skill-area scores, using the same summation grouped by area.
 *
 * WHAT IS DELIBERATELY NOT IMPLEMENTED:
 *
 *   - Heat-map BANDS. The SOW promises four scoring engines and defines the
 *     rules for two; band thresholds are undefined (deferred item L2). The
 *     per-area scores above are computed and stored; turning them into bands
 *     is a client decision, so bandFor() refuses rather than guessing.
 *
 *   - Any numeric meaning for Average / Good / Better / Best. Those are
 *     categorical answers, and this table never holds them.
 */
class ScoringService
{
    /**
     * The threshold the SOW states for the 18-question check. What FOLLOWS
     * from crossing it is not specified, so nothing acts on it here.
     */
    public const YES_COUNT_THRESHOLD = 10;

    public const SCHEME_VERSION = 'v1';

    /**
     * Score a submission with every rule that applies to it.
     *
     * @return Collection<int, SubmissionScore>
     */
    public function score(FormSubmission $submission): Collection
    {
        $this->assertScorable($submission);

        return DB::transaction(function () use ($submission): Collection {
            $scores = collect();

            $overall = $this->scoreOverall($submission);
            if ($overall !== null) {
                $scores->push($overall);
            }

            $yesCount = $this->scoreYesCount($submission);
            if ($yesCount !== null) {
                $scores->push($yesCount);
            }

            $scores = $scores->merge($this->scoreSkillAreas($submission));

            return $scores;
        });
    }

    /**
     * Rule 1: the overall numeric score, e.g. 35-question assessment out of 35.
     */
    public function scoreOverall(FormSubmission $submission): ?SubmissionScore
    {
        $max = (float) $submission->formVersion->questions()->sum('max_score');

        if ($max <= 0.0) {
            // Nothing in this version is numerically scored. Preserve the
            // answers; invent no score.
            return null;
        }

        $raw = $this->sumAnswers($submission->answers()->with(['question', 'answerOptions.questionOption'])->get());

        return $this->append($submission, 'overall', null, $raw, $max);
    }

    /**
     * Rule 2: the 18-question check - count the Yes answers.
     */
    public function scoreYesCount(FormSubmission $submission): ?SubmissionScore
    {
        $booleanQuestions = $submission->formVersion->questions()
            ->where('type', 'boolean')
            ->count();

        if ($booleanQuestions === 0) {
            return null;
        }

        $yes = $submission->answers()
            ->where('value_boolean', true)
            ->count();

        return $this->append($submission, 'yes_count', null, (float) $yes, (float) $booleanQuestions);
    }

    /**
     * Rule 3: per-skill-area scores, feeding the heat map's DATA.
     *
     * @return Collection<int, SubmissionScore>
     */
    public function scoreSkillAreas(FormSubmission $submission): Collection
    {
        $answers = $submission->answers()
            ->with(['question', 'answerOptions.questionOption'])
            ->get()
            ->filter(fn (Answer $a): bool => $a->question?->skill_area_id !== null);

        return $answers
            ->groupBy(fn (Answer $a): int => (int) $a->question->skill_area_id)
            ->map(function (Collection $group, int $skillAreaId) use ($submission): ?SubmissionScore {
                $max = (float) $group->sum(fn (Answer $a): float => (float) ($a->question->max_score ?? 0));

                if ($max <= 0.0) {
                    return null;
                }

                return $this->append($submission, 'skill_area', $skillAreaId, $this->sumAnswers($group), $max);
            })
            ->filter()
            ->values();
    }

    /**
     * Whether the 18-question check met the stated threshold.
     *
     * Derived, never stored: the SOW states the threshold but not its
     * consequence, so nothing is persisted that would imply one.
     */
    public function meetsYesCountThreshold(FormSubmission $submission): ?bool
    {
        $score = $submission->scores()
            ->where('score_type', 'yes_count')
            ->latest('computed_at')
            ->first();

        return $score === null ? null : (float) $score->raw_score >= self::YES_COUNT_THRESHOLD;
    }

    /**
     * The heat-map band for a score.
     *
     * NOT IMPLEMENTED, deliberately. The SOW does not define band
     * thresholds (deferred item L2), and inventing them would put a
     * fabricated judgement in front of the client.
     */
    public function bandFor(SubmissionScore $score): never
    {
        throw new RuntimeException(
            'Heat-map band thresholds are not defined by the requirements (deferred item L2). '
            .'The per-area scores are computed and stored; the banding rule must come from the client.'
        );
    }

    /**
     * The current score of a given type - the newest row, since the table is
     * append-only.
     */
    public function currentScore(FormSubmission $submission, string $scoreType, ?int $skillAreaId = null): ?SubmissionScore
    {
        return $submission->scores()
            ->where('score_type', $scoreType)
            ->when($skillAreaId === null,
                fn ($q) => $q->whereNull('skill_area_id'),
                fn ($q) => $q->where('skill_area_id', $skillAreaId),
            )
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  Collection<int, Answer>  $answers
     */
    private function sumAnswers(Collection $answers): float
    {
        return (float) $answers->sum(function (Answer $answer): float {
            // A selected option's score_value, where the option carries one.
            // Categorical options carry NULL and therefore contribute zero -
            // they are answers, not scores.
            $fromOptions = (float) $answer->answerOptions
                ->sum(fn ($selection): float => (float) ($selection->questionOption->score_value ?? 0));

            $fromNumber = (float) ($answer->value_number ?? 0);

            return $fromOptions + $fromNumber;
        });
    }

    private function append(
        FormSubmission $submission,
        string $scoreType,
        ?int $skillAreaId,
        float $raw,
        float $max,
    ): SubmissionScore {
        return SubmissionScore::writeThrough(function () use ($submission, $scoreType, $skillAreaId, $raw, $max): SubmissionScore {
            $score = new SubmissionScore;
            $score->forceFill([
                'form_submission_id' => $submission->getKey(),
                'skill_area_id' => $skillAreaId,
                'score_type' => $scoreType,
                'raw_score' => round($raw, 2),
                'max_score' => round($max, 2),
                'percentage' => $max > 0 ? round(min($raw / $max * 100, 100), 2) : null,
                'scheme_version' => self::SCHEME_VERSION,
                'computed_at' => now(),
            ])->save();

            return $score->fresh();
        });
    }

    private function assertScorable(FormSubmission $submission): void
    {
        if (! $submission->formVersion->formTemplate->is_scored) {
            throw new RuntimeException(
                "Form template {$submission->formVersion->form_template_id} is not a scored instrument."
            );
        }
    }
}
