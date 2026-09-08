<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What one scheduler run did.
 *
 * Deferred triggers are reported rather than omitted: a trigger that cannot
 * run because a later phase has not been built, or because a client decision
 * is open, must be visible in the run's output. Silence would look exactly
 * like "nobody qualified".
 */
final class SweepResult
{
    /** @var array<string, int> */
    public array $created = [];

    /** @var array<string, int> */
    public array $duplicates = [];

    /** @var array<string, int> */
    public array $suppressed = [];

    /** @var array<string, string> trigger key => reason */
    public array $deferred = [];

    public function recordCreated(string $trigger): void
    {
        $this->created[$trigger] = ($this->created[$trigger] ?? 0) + 1;
    }

    public function recordDuplicate(string $trigger): void
    {
        $this->duplicates[$trigger] = ($this->duplicates[$trigger] ?? 0) + 1;
    }

    public function recordSuppressed(string $trigger): void
    {
        $this->suppressed[$trigger] = ($this->suppressed[$trigger] ?? 0) + 1;
    }

    public function recordDeferred(string $trigger, string $reason): void
    {
        $this->deferred[$trigger] = $reason;
    }

    public function totalCreated(): int
    {
        return array_sum($this->created);
    }

    public function totalDuplicates(): int
    {
        return array_sum($this->duplicates);
    }

    public function totalSuppressed(): int
    {
        return array_sum($this->suppressed);
    }
}
