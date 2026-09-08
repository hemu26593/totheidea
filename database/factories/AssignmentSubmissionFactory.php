<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use App\Models\SessionInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentSubmission>
 *
 * The enrolment is derived from the assignment's batch (instance ->
 * session_instance -> batch) and customer_id from that enrolment, so the
 * factory satisfies I4 and I15 by construction. A test that needs a violating
 * pair builds it deliberately rather than getting one by accident.
 */
class AssignmentSubmissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignment_instance_id' => AssignmentInstance::factory()->released(),
            'enrollment_id' => fn (array $attributes): int => Enrollment::factory()->create([
                'batch_id' => SessionInstance::query()->findOrFail(
                    AssignmentInstance::query()
                        ->findOrFail($attributes['assignment_instance_id'])->session_instance_id
                )->batch_id,
            ])->getKey(),
            'customer_id' => fn (array $attributes): int => (int) Enrollment::query()
                ->findOrFail($attributes['enrollment_id'])->customer_id,
            'status' => AssignmentSubmission::STATUS_IN_PROGRESS,
            'attempt_number' => 1,
            'source' => ActorSource::InternalUser,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => AssignmentSubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }
}
