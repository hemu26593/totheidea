<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Forms\ParticipantQuestionnaire;
use App\Domain\Programme\EnrollmentService;
use App\Enums\AuditAction;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
        private readonly CustomerContactService $contacts,
        private readonly ParticipantQuestionnaire $questionnaire,
    ) {}

    /**
     * Bring a customer into the programme in one action: the business, the
     * person we deal with, the batch they are running in, and the
     * questionnaire link that follows from all three.
     *
     * Every customer reaching this system is already confirmed, so programme
     * entry is one action: the batch assignment IS the activation. There is no
     * payment, no onboarding stage and no enrol step anywhere - the enrolment
     * row is created here because twelve tables carry a NOT NULL
     * enrollment_id and resolve customer isolation through it, not because
     * anybody is enrolling anyone. Nothing in the UI creates one (ADR-017).
     *
     * The contact is created through CustomerContactService as the primary
     * one, so it is the same primary contact the rest of the system already
     * knows: RecipientResolver finds it, and a form link can be sent without
     * anybody revisiting the record to add it. There is no second primary
     * mechanism here.
     *
     * The three records share ONE transaction. A customer saved without the
     * batch or the contact they were entered with is precisely the
     * half-finished state this exists to remove, so any failure takes the
     * whole thing with it and the operator sees why.
     *
     * THE QUESTIONNAIRE LINK IS ISSUED AFTER THAT TRANSACTION COMMITS, on
     * purpose, and twice over:
     *
     *   - issuing it emails a business, when a transport is configured. A
     *     message that went out for a customer the transaction then rolled
     *     back cannot be recalled.
     *   - a refusal here must not destroy a customer who is otherwise
     *     complete. It is reported on the result instead.
     *
     * @param  array{name: string, code: string}  $attributes
     * @param  array{name: string, email?: string|null, phone_e164?: string|null}|null  $contact
     */
    public function createInBatch(array $attributes, ?Batch $batch, ?array $contact, User $actor): ProgrammeEntry
    {
        /** @var array{0: Customer, 1: Enrollment|null} $entered */
        $entered = DB::transaction(function () use ($attributes, $batch, $contact, $actor): array {
            $customer = $this->create($attributes, $actor);
            $enrollment = null;

            if ($contact !== null) {
                $this->contacts->create($customer, $contact, $actor, primary: true);
            }

            if ($batch !== null) {
                $enrollment = $this->enrollments->enrol($customer, $batch, $actor);
            }

            return [$customer->fresh(), $enrollment];
        });

        [$customer, $enrollment] = $entered;

        if ($enrollment === null) {
            return new ProgrammeEntry($customer, null, null);
        }

        // A business in a batch is given the participant questionnaire, with
        // no further action from anybody. The service decides whether there is
        // one to give and whether this enrolment already holds a live link, so
        // re-entry cannot produce a second.
        try {
            return new ProgrammeEntry(
                $customer,
                $enrollment,
                $this->questionnaire->assign($enrollment, $actor),
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return new ProgrammeEntry($customer, $enrollment, null, $e->getMessage());
        }
    }

    /**
     * A customer is created active.
     *
     * Everyone entered here is already a confirmed customer, so there is no
     * stage to clear and no separate "activate" click standing between a new
     * record and the programme. Status is set on the insert rather than by
     * transitioning immediately afterwards: the customer was never anything
     * else, and a two-entry audit trail saying otherwise would be a fiction.
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
