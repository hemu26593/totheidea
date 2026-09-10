<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * All write operations on customers.
 *
 * There is deliberately NO delete method. Customers are archived (ADR-011) so
 * that historical customer-owned data stays available; the model refuses
 * deletion outright and every child table RESTRICTs.
 */
class CustomerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, code: string}  $attributes
     */
    public function create(array $attributes, User $actor): Customer
    {
        return DB::transaction(function () use ($attributes, $actor): Customer {
            $customer = Customer::create($attributes);

            $this->audit->log(
                AuditAction::CustomerCreated,
                $customer,
                null,
                ['name' => $customer->name, 'code' => $customer->code],
                $actor,
            );

            return $customer->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Customer $customer, array $attributes, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $attributes, $actor): Customer {
            $before = $customer->only(array_keys($attributes));

            $customer->fill($attributes)->save();

            $this->audit->logChanges(
                AuditAction::CustomerUpdated,
                $customer,
                $before,
                $customer->only(array_keys($attributes)),
                $actor,
            );

            return $customer->fresh();
        });
    }

    public function activate(Customer $customer, User $actor): Customer
    {
        return $this->transitionTo($customer, 'active', $actor);
    }

    /**
     * Archiving is what replaces deletion. The record and everything it owns
     * remain queryable.
     */
    public function archive(Customer $customer, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $actor): Customer {
            $before = ['status' => $customer->status, 'archived_at' => $customer->archived_at];

            $customer->forceFill([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $actor->getKey(),
            ])->save();

            $this->audit->log(
                AuditAction::CustomerArchived,
                $customer,
                $before,
                ['status' => 'archived', 'archived_at' => $customer->archived_at],
                $actor,
            );

            return $customer->fresh();
        });
    }

    private function transitionTo(Customer $customer, string $status, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $status, $actor): Customer {
            $before = ['status' => $customer->status];

            $customer->forceFill(['status' => $status])->save();

            $this->audit->logChanges(
                AuditAction::CustomerUpdated,
                $customer,
                $before,
                ['status' => $status],
                $actor,
            );

            return $customer->fresh();
        });
    }
}
