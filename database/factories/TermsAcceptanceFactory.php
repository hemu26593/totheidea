<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Enrollment;
use App\Models\TermsAcceptance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermsAcceptance>
 */
class TermsAcceptanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'terms_version' => 'v1',
            'accepted_name' => fake()->name(),
            'accepted_at' => now(),
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }
}
