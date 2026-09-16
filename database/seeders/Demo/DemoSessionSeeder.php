<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Sessions\AttendanceService;
use App\Domain\Sessions\SessionSchedulingService;
use App\Models\Batch;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sessions across every batch, and who turned up to the ones already held.
 *
 * Each batch gets its six sessions a fortnight apart from its own start date,
 * so the batches that started earlier are further through the curriculum. That
 * spread is the point: the dashboard shows completed sessions behind and
 * scheduled sessions ahead, because the batches genuinely are at different
 * stages, not because a status column was set to make it look that way.
 *
 * Attendance is only marked for sessions that have actually happened. Marking
 * a future session would be the kind of detail that survives a demo and then
 * confuses somebody three months later.
 */
class DemoSessionSeeder extends Seeder
{
    /**
     * Who was absent, and where. Fixed rather than random so that every reseed
     * tells the same story and a demonstration can be rehearsed.
     *
     * Keyed by customer code, then session sequence.
     *
     * @var array<string, array<int, string>>
     */
    private const EXCEPTIONS = [
        'BMP-VARDHAN' => [2 => SessionAttendance::STATUS_ABSENT, 5 => SessionAttendance::STATUS_LATE],
        'BMP-ARVIND' => [3 => SessionAttendance::STATUS_LATE],
        'BMP-ZENITH' => [4 => SessionAttendance::STATUS_EXCUSED],
        'BMP-SHREEJI' => [2 => SessionAttendance::STATUS_LATE],
        'BMP-MERIDIAN' => [3 => SessionAttendance::STATUS_ABSENT],
        'BMP-SANKALP' => [1 => SessionAttendance::STATUS_LATE],
        'BMP-GIRIRAJ' => [1 => SessionAttendance::STATUS_ABSENT],
    ];

    public function run(): void
    {
        $actor = DemoTeamSeeder::actor();
        $consultants = DemoTeamSeeder::consultants();
        $scheduling = app(SessionSchedulingService::class);
        $attendance = app(AttendanceService::class);

        $today = CarbonImmutable::today();

        foreach (Batch::query()->with('program')->orderBy('id')->get() as $index => $batch) {
            if (SessionInstance::query()->where('batch_id', $batch->getKey())->exists()) {
                continue;
            }

            $startsOn = CarbonImmutable::parse($batch->starts_on);

            // Six sessions, one per fortnight from the batch's own start.
            // Keyed by session sequence, which is what scheduleCurriculum asks for.
            $dates = [];
            foreach (range(1, 6) as $sequence) {
                $dates[$sequence] = $startsOn->addWeeks(($sequence - 1) * 2)->toDateString();
            }

            $conductedBy = $consultants[$index % count($consultants)];

            $instances = $scheduling->scheduleCurriculum($batch, $dates, $actor);

            foreach ($instances as $instance) {
                $instance->forceFill([
                    'venue' => $this->venueFor($batch->code),
                    'conducted_by' => $conductedBy->getKey(),
                    'starts_at' => '10:00:00',
                ])->save();
            }

            $enrollments = $batch->enrollments()->with('customer')->get();

            foreach ($instances as $instance) {
                $planned = CarbonImmutable::parse($instance->planned_date);

                if ($planned->greaterThanOrEqualTo($today)) {
                    continue;
                }

                // A session in the past is a session that happened.
                $scheduling->begin($instance->fresh(), $planned->toDateString(), $actor);
                $held = $scheduling->complete($instance->fresh(), $actor, $planned->toDateString());

                foreach ($enrollments as $enrollment) {
                    if ($attendance->hasBeenMarked($held, $enrollment)) {
                        continue;
                    }

                    $attendance->mark(
                        $held,
                        $enrollment,
                        $this->statusFor(
                            (string) $enrollment->customer?->code,
                            (int) $held->sessionTemplate->sequence,
                        ),
                        $actor,
                        null,
                        SessionAttendance::MARKING_METHODS[0] ?? null,
                    );
                }
            }
        }
    }

    private function statusFor(string $customerCode, int $sequence): string
    {
        return self::EXCEPTIONS[$customerCode][$sequence] ?? SessionAttendance::STATUS_PRESENT;
    }

    private function venueFor(string $batchCode): string
    {
        return match (true) {
            str_contains($batchCode, 'VAD') => 'Growmatic Action Centre, Alkapuri, Vadodara',
            str_contains($batchCode, 'AHM') => 'Growmatic Action Centre, Prahlad Nagar, Ahmedabad',
            default => 'Growmatic Action Centre, Alkapuri, Vadodara',
        };
    }
}
