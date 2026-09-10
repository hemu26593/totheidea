<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 *
 * score_value is null by default. A numeric value is the deliberate
 * exception, and never for a categorical label.
 */
class QuestionOptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'value' => fake()->unique()->bothify('opt_####'),
            'label' => fake()->word(),
            'score_value' => null,
            'position' => 0,
        ];
    }

    public function worth(float $score): static
    {
        return $this->state(fn (): array => ['score_value' => $score]);
    }
}
