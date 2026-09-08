<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiApprovalDecision;
use App\Models\AiApproval;
use App\Models\AiGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiApproval>
 *
 * decided_by defaults to a NEW user rather than the generation's own
 * generated_by, because the model refuses a self-approval outright. A factory
 * that produced one would fail on every call, which is the correct behaviour
 * and a poor default.
 */
class AiApprovalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ai_generation_id' => AiGeneration::factory(),
            'decision' => AiApprovalDecision::Approved,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'remark' => null,
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['decision' => AiApprovalDecision::Rejected]);
    }
}
