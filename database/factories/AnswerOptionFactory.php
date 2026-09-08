<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Answer;
use App\Models\AnswerOption;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnswerOption> */
class AnswerOptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'answer_id' => Answer::factory(),
            'question_option_id' => QuestionOption::factory(),
        ];
    }
}
