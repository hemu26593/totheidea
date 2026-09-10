<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\ReportArtifact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ReportArtifact>
 *
 * The subject defaults to an enrolment, which is what most reports are about.
 * A test needing a batch-subject artifact sets it explicitly, so a factory can
 * never quietly produce a subject whose ownership does not resolve.
 */
class ReportArtifactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'report_key' => 'participant_progress',
            'subject_type' => (new Enrollment)->getMorphClass(),
            'subject_id' => Enrollment::factory(),
            'format' => ReportArtifact::FORMAT_PDF,
            'disk' => 'local',
            'path' => fn (array $attributes): string => sprintf(
                'reports/%s/%s.%s',
                $attributes['report_key'],
                bin2hex(random_bytes(8)),
                $attributes['format'],
            ),
            'parameters' => [],
            'checksum_sha256' => hash('sha256', bin2hex(random_bytes(16))),
            'generated_by' => User::factory(),
            'generated_at' => now(),
        ];
    }

    public function csv(): static
    {
        return $this->state(fn (): array => ['format' => ReportArtifact::FORMAT_CSV]);
    }

    public function forSubject(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }
}
