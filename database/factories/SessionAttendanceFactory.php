<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorSource;
use App\Models\Enrollment;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionAttendance>
 *
 * The enrolment is derived from the session's batch, and customer_id from that
 * enrolment. Generating either independently would produce exactly the
 * mis-scoped, cross-batch row the invariants exist to prevent, and the tests
 * would then be proving something about impossible data.
 */
class SessionAttendanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_instance_id' => SessionInstance::factory()->held(),
            'enrollment_id' => fn (array $attributes): int => Enrollment::factory()->create([
                'batch_id' => SessionInstance::query()
                    ->findOrFail($attributes['session_instance_id'])->batch_id,
            ])->getKey(),
            'customer_id' => fn (array $attributes): int => (int) Enrollment::query()
                ->findOrFail($attributes['enrollment_id'])->customer_id,
            'status' => SessionAttendance::STATUS_PRESENT,
            'marked_at' => now(),
            'marked_by' => User::factory(),
            'source' => ActorSource::InternalUser,
            'created_by' => fn (array $attributes): mixed => $attributes['marked_by'],
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
