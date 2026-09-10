<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Exceptions\AuthorizationRuleException;
use App\Exceptions\CustomerIsolationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Surface a refused domain invariant as a form error rather than a 500.
 *
 * The policy has already rejected the ordinary cases with a 403 by the time
 * anything here runs. Reaching a service guard means a race, a stale page, or
 * a rule the UI cannot express as a disabled button - all worth showing to the
 * person who tried, rather than swallowing or crashing.
 *
 * An isolation failure is deliberately NOT reported in detail: whether another
 * customer's record exists is itself information.
 */
trait ReportsDomainFailures
{
    protected function runGuarded(callable $operation, string $success = 'Saved.', string $errorBag = 'domain'): bool
    {
        try {
            $operation();
        } catch (CustomerIsolationException) {
            abort(404);
        } catch (AuthorizationRuleException|RuntimeException|InvalidArgumentException $e) {
            $this->addError($errorBag, $e->getMessage());

            return false;
        }

        session()->flash('status', $success);

        return true;
    }
}
