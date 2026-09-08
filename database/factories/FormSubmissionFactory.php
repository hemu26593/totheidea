<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmission>
 *
 * customer_id is derived from the enrolment rather than generated
 * independently, so the factory can never produce the mis-scoped row the
 * redundant-copy invariant exists to prevent.
 */
class FormSubmissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_version_id' => FormVersion::factory(),
            'enrollment_id' => Enrollment::factory(),
            'customer_id' => fn (array $attributes): int => (int) Enrollment::query()
                ->findOrFail($attributes['enrollment_id'])->customer_id,
            'status' => 'draft',
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }
}
