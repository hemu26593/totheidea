<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Answer;
use App\Models\FormSubmission;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Answer> */
class AnswerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_submission_id' => FormSubmission::factory(),
            'question_id' => Question::factory(),
            'value_text' => fake()->sentence(),
        ];
    }

    public function yes(): static
    {
        return $this->state(fn (): array => ['value_text' => null, 'value_boolean' => true]);
    }

    public function no(): static
    {
        return $this->state(fn (): array => ['value_text' => null, 'value_boolean' => false]);
    }
}
