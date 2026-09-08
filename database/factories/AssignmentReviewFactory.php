<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentReview>
 *
 * reviewed_by is always a real user: a review is an internal act by
 * construction, and there is no state that makes it anything else.
 */
class AssignmentReviewFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignment_submission_id' => AssignmentSubmission::factory()->submitted(),
            'decision' => AssignmentReview::DECISION_RETURNED,
            'remark' => $this->faker->sentence(),
            'attempt_number' => fn (array $attributes): int => (int) AssignmentSubmission::query()
                ->findOrFail($attributes['assignment_submission_id'])->attempt_number,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['decision' => AssignmentReview::DECISION_ACCEPTED]);
    }
}
