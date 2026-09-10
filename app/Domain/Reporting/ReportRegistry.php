<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Builders\AssignmentStatusReport;
use App\Domain\Reporting\Builders\AttendanceRegisterReport;
use App\Domain\Reporting\Builders\BatchSummaryReport;
use App\Domain\Reporting\Builders\ParticipantProgressReport;
use App\Domain\Reporting\Contracts\ReportBuilder;
use InvalidArgumentException;

/**
 * The reports this phase builds, by key.
 *
 * A closed list, resolved by an exact key match. A report key arrives from a
 * request, so it is never used to construct a class name - only to look one up
 * here.
 *
 * `diagnostic` is part of the report_artifacts vocabulary and is deliberately
 * absent from this registry: no builder for it exists yet, and asking for it
 * fails loudly rather than producing an empty document.
 */
class ReportRegistry
{
    /** @var array<string, class-string<ReportBuilder>> */
    private const BUILDERS = [
        'participant_progress' => ParticipantProgressReport::class,
        'batch_summary' => BatchSummaryReport::class,
        'attendance_register' => AttendanceRegisterReport::class,
        'assignment_status' => AssignmentStatusReport::class,
    ];

    public function has(string $reportKey): bool
    {
        return array_key_exists($reportKey, self::BUILDERS);
    }

    public function get(string $reportKey): ReportBuilder
    {
        if (! $this->has($reportKey)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown report [%s]. Available: %s.',
                $reportKey,
                implode(', ', array_keys(self::BUILDERS)),
            ));
        }

        return app(self::BUILDERS[$reportKey]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys(self::BUILDERS);
    }
}
