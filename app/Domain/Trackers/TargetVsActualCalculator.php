<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\MmdTarget;
use Illuminate\Support\Facades\DB;

/**
 * Target versus actual, computed on demand.
 *
 * NOTHING HERE IS STORED. There is no mmd_dashboard table and no cached
 * comparison, because an amended figure must change the conclusion drawn from
 * it - and a stored comparison would go stale the moment an entry was
 * corrected.
 *
 * Laravel computes every number. No model, no AI and no external service
 * produces a figure this returns.
 *
 * [B1 CLIENT DECISION] The actual is a SUM over the target's date range. That
 * is deliberately grain-agnostic: under answer A the range holds one row per
 * day and the sum is those rows; under answer B it holds a contributor's row
 * each and the sum is the business total. The comparison is correct either
 * way, so answering B1 changes nothing in this class.
 */
class TargetVsActualCalculator
{
    /**
     * The actual figure for one metric over one period.
     *
     * Returns null when nothing was recorded at all - which is a different
     * statement from "zero", and the two must not be conflated in a report.
     */
    public function actual(
        Customer $customer,
        string $metric,
        string $periodStart,
        string $periodEnd,
    ): ?float {
        $row = MmdEntry::query()
            ->where('customer_id', $customer->getKey())
            ->whereDate('entry_date', '>=', $periodStart)
            ->whereDate('entry_date', '<=', $periodEnd)
            ->selectRaw('SUM('.$this->column($metric).') as total, COUNT(*) as rows')
            ->first();

        if ($row === null || (int) $row->rows === 0) {
            return null;
        }

        return $row->total === null ? null : (float) $row->total;
    }

    /**
     * A target and its actual, side by side.
     *
     * @return array{metric: string, target: float, actual: float|null, variance: float|null, period_start: string, period_end: string}
     */
    public function compare(MmdTarget $target, Customer $customer): array
    {
        $actual = $this->actual(
            $customer,
            $target->metric,
            $target->period_start->toDateString(),
            $target->period_end->toDateString(),
        );

        return [
            'metric' => $target->metric,
            'target' => (float) $target->target_value,
            'actual' => $actual,
            // Simple arithmetic on two recorded figures. No weighting, no
            // banding and no grade: what a variance MEANS is not specified,
            // so nothing here interprets it.
            'variance' => $actual === null ? null : $actual - (float) $target->target_value,
            'period_start' => $target->period_start->toDateString(),
            'period_end' => $target->period_end->toDateString(),
        ];
    }

    /**
     * Every target set for an enrolment, against its actuals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scorecard(Enrollment $enrollment): array
    {
        $customer = $enrollment->customer()->first();

        if ($customer === null) {
            return [];
        }

        return MmdTarget::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderBy('period_start')
            ->orderBy('metric')
            ->get()
            ->map(fn (MmdTarget $target): array => $this->compare($target, $customer))
            ->all();
    }

    /**
     * Guard the column name: it reaches a raw SUM, so it is checked against
     * the fixed measure set rather than trusted.
     */
    private function column(string $metric): string
    {
        if (! in_array($metric, MmdEntry::METRICS, true)) {
            throw new \InvalidArgumentException("Unknown MMD metric [{$metric}].");
        }

        return DB::getQueryGrammar()->wrap($metric);
    }
}
