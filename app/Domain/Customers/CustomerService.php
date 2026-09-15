<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Programme\EnrollmentService;
use App\Enums\AuditAction;
use App\Models\Batch;
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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * Create a customer and, when a batch is given, put them straight into it.
     *
     * Every customer reaching this system is already confirmed, so programme
     * entry is one action: the batch assignment IS the activation. There is no
     * payment step and no second "enrol" click - the enrolment row is created
     * here because twelve tables carry a NOT NULL enrollment_id and resolve
     * customer isolation through it, not because the user is enrolling anyone.
     *
     * Both writes share one transaction: a customer saved without the batch
     * they were meant to be in is precisely the half-finished state this
     * change exists to remove, so a failed assignment takes the customer with
     * it and the operator sees why.
     *
     * @param  array{name: string, code: string}  $attributes
     */
    public function createInBatch(array $attributes, ?Batch $batch, User $actor): Customer
    {
        return DB::transaction(function () use ($attributes, $batch, $actor): Customer {
            $customer = $this->create($attributes, $actor);

            if ($batch !== null) {
                $this->enrollments->enrol($customer, $batch, $actor);
            }

            return $customer->fresh();
        });
    }

    /**
     * A customer is created active, not as a prospect.
     *
     * Everyone entered here is already a confirmed customer, so there is no
     * prospect stage to clear and no separate "activate" click standing
     * between a new record and the programme. Status is set on the insert
     * rather than by transitioning immediately afterwards: the customer was
     * never a prospect, and a two-entry audit trail saying otherwise would be
     * a fiction. The column keeps its 'prospect' default so rows created
     * before this rule, and any written outside this service, are unchanged.
     *
     * @param  array{name: string, code: string}  $attributes
     */
    public function create(array $attributes, User $actor): Customer
    {
        return DB::transaction(function () use ($attributes, $actor): Customer {
            $customer = Customer::create($attributes);
            $customer->forceFill(['status' => 'active'])->save();

            $this->audit->log(
                AuditAction::CustomerCreated,
                $customer,
                null,
                ['name' => $customer->name, 'code' => $customer->code, 'status' => 'active'],
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
