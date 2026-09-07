<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * All write operations on customer contacts.
 *
 * The at-most-one-primary invariant is a count across rows, so it cannot be a
 * database constraint and lives here instead - transactionally, so a
 * concurrent promotion cannot leave a business with two primary contacts.
 *
 * Consent changes are audited: they are the evidence that a message was
 * permitted.
 */
class CustomerContactService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Customer $customer, array $attributes, User $actor, bool $primary = false): CustomerContact
    {
        return DB::transaction(function () use ($customer, $attributes, $actor, $primary): CustomerContact {
            $contact = new CustomerContact($attributes);
            $contact->customer_id = $customer->getKey();
            $contact->save();

            if ($primary) {
                $this->promote($contact);
            }

            $this->audit->log(
                AuditAction::CustomerCreated,
                $contact,
                null,
                ['customer_id' => $customer->getKey(), 'name' => $contact->name, 'is_primary' => $primary],
                $actor,
            );

            return $contact->fresh();
        });
    }

    /**
     * Make this contact the customer's primary, demoting any other.
     *
     * Both writes happen in one transaction so the invariant holds at every
     * observable point: there is never a moment with two primaries, and never
     * a moment with none.
     */
    public function makePrimary(CustomerContact $contact, User $actor): CustomerContact
    {
        return DB::transaction(function () use ($contact, $actor): CustomerContact {
            $this->promote($contact);

            $this->audit->log(
                AuditAction::CustomerUpdated,
                $contact,
                ['is_primary' => false],
                ['is_primary' => true],
                $actor,
            );

            return $contact->fresh();
        });
    }

    /**
     * @param  array{email_opt_in?: bool, whatsapp_opt_in?: bool}  $consent
     */
    public function updateConsent(CustomerContact $contact, array $consent, User $actor): CustomerContact
    {
        return DB::transaction(function () use ($contact, $consent, $actor): CustomerContact {
            $before = $contact->only(array_keys($consent));

            $contact->fill($consent)->save();

            // Consent is the evidence that a send was permitted, so a change
            // to it is always recorded even when nothing else changed.
            $this->audit->log(
                AuditAction::CustomerContactConsentChanged,
                $contact,
                $before,
                $contact->only(array_keys($consent)),
                $actor,
            );

            return $contact->fresh();
        });
    }

    public function archive(CustomerContact $contact, User $actor): CustomerContact
    {
        return DB::transaction(function () use ($contact, $actor): CustomerContact {
            // A contact that received reminders must stay resolvable from
            // notification_dispatches, so it is archived rather than deleted.
            $contact->forceFill(['archived_at' => now(), 'is_primary' => false])->save();

            $this->audit->log(AuditAction::CustomerUpdated, $contact, null, ['archived' => true], $actor);

            return $contact->fresh();
        });
    }

    /**
     * Guard against a caller pairing a contact with someone else's customer.
     */
    public function assertBelongsTo(CustomerContact $contact, Customer $customer): void
    {
        if ((int) $contact->customer_id !== (int) $customer->getKey()) {
            throw CustomerIsolationException::mismatch(
                'contact '.$contact->getKey(),
                'customer '.$customer->getKey(),
                'customer '.$contact->customer_id,
            );
        }
    }

    private function promote(CustomerContact $contact): void
    {
        CustomerContact::query()
            ->where('customer_id', $contact->customer_id)
            ->whereKeyNot($contact->getKey())
            ->update(['is_primary' => false]);

        $contact->forceFill(['is_primary' => true])->save();
    }
}
