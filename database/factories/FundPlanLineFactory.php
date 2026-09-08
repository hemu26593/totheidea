<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DueClassification;
use App\Enums\FundPlanSection;
use App\Enums\PlanningType;
use App\Models\FundPlan;
use App\Models\FundPlanLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundPlanLine>
 *
 * Defaults to a fund_out line with no due classification, so the factory
 * cannot accidentally produce the I6-violating row (a classification outside
 * fund_in) that a test must build deliberately.
 */
class FundPlanLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fund_plan_id' => FundPlan::factory(),
            'section' => FundPlanSection::FundOut,
            'planning_type' => PlanningType::BasicFinancePlanning,
            'due_classification' => null,
            'label' => $this->faker->words(2, true),
            'week_number' => null,
            'planned_amount' => 5000,
            'actual_amount' => null,
            'position' => 0,
        ];
    }

    /**
     * A receivable line - the only section where an aging classification is
     * meaningful.
     */
    public function fundIn(DueClassification $classification = DueClassification::CurrentDue): static
    {
        return $this->state(fn (): array => [
            'section' => FundPlanSection::FundIn,
            'due_classification' => $classification,
        ]);
    }

    public function weekly(int $week): static
    {
        return $this->state(fn (): array => ['week_number' => $week]);
    }
}
