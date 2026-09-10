<?php

declare(strict_types=1);

namespace App\Domain\Trackers;

use App\Models\Customer;
use App\Models\MmdEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The T / Th / S weekly presentation.
 *
 * T / Th / S = Tuesday / Thursday / Saturday.
 *
 * A DERIVED VIEW OVER DAILY ROWS, NOT MEASURES. There is no weekday column on
 * mmd_entries and there never will be: Tuesday, Thursday and Saturday are how
 * a week is presented for review, not something a business records. A column
 * would have to be kept in step with entry_date, and the two would eventually
 * disagree.
 *
 * [L6] Whether an entry is EXPECTED daily or only on those three days is an
 * open client decision. It changes the cadence of notification trigger 5; it
 * changes nothing here, because this projector reports what was recorded
 * rather than what should have been.
 */
class WeeklyReviewProjector
{
    /**
     * The three review days, in the SOW's order. Carried exactly as supplied.
     *
     * @var array<string, int> label => ISO-8601 day of week
     */
    public const REVIEW_DAYS = [
        'T' => 2,
        'Th' => 4,
        'S' => 6,
    ];

    /**
     * One week of figures, keyed by review day.
     *
     * Every metric is summed over the rows falling on that weekday, which is
     * correct under either answer to [B1 CLIENT DECISION]: one row summed is
     * that row.
     *
     * @return array<string, array<string, float|int|null>>
     */
    public function week(Customer $customer, DateTimeInterface|string $weekContaining): array
    {
        $start = CarbonImmutable::parse($weekContaining)->startOfWeek();
        $end = $start->addDays(6);

        $entries = MmdEntry::query()
            ->where('customer_id', $customer->getKey())
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->get();

        $projection = [];

        foreach (self::REVIEW_DAYS as $label => $isoDay) {
            $onThatDay = $entries->filter(
                fn (MmdEntry $entry): bool => $entry->entry_date->dayOfWeekIso === $isoDay,
            );

            $projection[$label] = $this->totals($onThatDay);
        }

        return $projection;
    }

    /**
     * Which of the three review days have figures, and which do not.
     *
     * Reports presence, not compliance: whether an absence is a MISS depends
     * on [L6], which is unanswered.
     *
     * @return array<string, bool>
     */
    public function recordedOn(Customer $customer, DateTimeInterface|string $weekContaining): array
    {
        return array_map(
            fn (array $totals): bool => $totals['entry_count'] > 0,
            $this->week($customer, $weekContaining),
        );
    }

    /**
     * @param  Collection<int, MmdEntry>  $entries
     * @return array<string, float|int|null>
     */
    private function totals($entries): array
    {
        $totals = ['entry_count' => $entries->count()];

        foreach (MmdEntry::METRICS as $metric) {
            $values = $entries->pluck($metric)->filter(fn (mixed $v): bool => $v !== null);

            $totals[$metric] = $values->isEmpty()
                // Nothing recorded is not the same statement as zero.
                ? null
                : (float) $values->sum();
        }

        return $totals;
    }
}
