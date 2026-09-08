<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormSubmission;
use App\Models\SubmissionScore;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<SubmissionScore>
 *
 * submission_scores refuse writes from anywhere but ScoringService, so this
 * factory - which is test infrastructure standing in for that service - must
 * open the same gate explicitly. That is deliberate: it means the guard
 * cannot be bypassed accidentally, only on purpose and visibly.
 */
class SubmissionScoreFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_submission_id' => FormSubmission::factory(),
            'skill_area_id' => null,
            'score_type' => 'overall',
            'raw_score' => 10,
            'max_score' => 35,
            'percentage' => 28.57,
            'scheme_version' => 'v1',
            'computed_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return SubmissionScore::writeThrough(fn () => parent::create($attributes, $parent));
    }
}
