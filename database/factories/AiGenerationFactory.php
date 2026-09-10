<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Models\AiGeneration;
use App\Models\AiPromptVersion;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGeneration>
 *
 * customer_id has no default beyond a fresh customer: there is no unscoped
 * generation, and a factory that quietly reused one would hide exactly the
 * isolation mistakes the tests are looking for.
 */
class AiGenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'ai_prompt_version_id' => AiPromptVersion::factory(),
            'purpose' => AiPurpose::FormDraft,
            'input_context' => ['facts' => []],
            'raw_output' => null,
            'validated_output' => null,
            'status' => AiGenerationStatus::Pending,
            'validation_error' => null,
            'resulting_form_version_id' => null,
            'generated_by' => User::factory(),
            'generated_at' => now(),
            'tokens_used' => null,
        ];
    }

    public function succeeded(array $validatedOutput = []): static
    {
        return $this->state(fn (): array => [
            'status' => AiGenerationStatus::Succeeded,
            'raw_output' => json_encode($validatedOutput),
            'validated_output' => $validatedOutput,
        ]);
    }

    public function awaitingApproval(): static
    {
        return $this->state(fn (): array => ['status' => AiGenerationStatus::AwaitingApproval]);
    }

    public function failed(string $reason = 'schema violation'): static
    {
        return $this->state(fn (): array => [
            'status' => AiGenerationStatus::Failed,
            'validation_error' => $reason,
        ]);
    }

    public function generatedBy(User $user): static
    {
        return $this->state(fn (): array => ['generated_by' => $user->getKey()]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->getKey()]);
    }
}
