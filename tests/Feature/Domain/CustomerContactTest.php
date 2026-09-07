<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Customers\CustomerContactService;
use App\Enums\AuditAction;
use App\Exceptions\CustomerIsolationException;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CustomerContactTest extends TestCase
{
    use RefreshDatabase;

    private CustomerContactService $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->contacts = app(CustomerContactService::class);
    }

    #[Test]
    public function a_contact_is_created_against_its_customer(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();

        $contact = $this->contacts->create($customer, [
            'name' => 'Raajul', 'email' => 'r@example.test',
        ], $actor);

        $this->assertSame($customer->getKey(), $contact->customer_id);
        $this->assertFalse($contact->is_primary);
        // Fails closed: WhatsApp is billed to the client and consent-sensitive.
        $this->assertFalse($contact->whatsapp_opt_in);
        $this->assertTrue($contact->email_opt_in);
    }

    #[Test]
    public function a_customer_has_at_most_one_primary_contact(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();

        $first = $this->contacts->create($customer, ['name' => 'A', 'email' => 'a@example.test'], $actor, primary: true);
        $second = $this->contacts->create($customer, ['name' => 'B', 'email' => 'b@example.test'], $actor, primary: true);

        $this->assertSame(
            1,
            CustomerContact::query()->where('customer_id', $customer->getKey())->where('is_primary', true)->count(),
            'A business must never have two primary contacts.'
        );
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    #[Test]
    public function promoting_a_contact_demotes_the_previous_primary(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();

        $first = $this->contacts->create($customer, ['name' => 'A', 'email' => 'a@example.test'], $actor, primary: true);
        $second = $this->contacts->create($customer, ['name' => 'B', 'email' => 'b@example.test'], $actor);

        $this->contacts->makePrimary($second, $actor);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame($second->getKey(), $customer->fresh()->primaryContact()?->getKey());
    }

    #[Test]
    public function promotion_does_not_reach_across_customers(): void
    {
        $actor = $this->admin();
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();

        $aPrimary = $this->contacts->create($a, ['name' => 'A', 'email' => 'a@example.test'], $actor, primary: true);
        $bContact = $this->contacts->create($b, ['name' => 'B', 'email' => 'b@example.test'], $actor);

        $this->contacts->makePrimary($bContact, $actor);

        // Customer A's primary is untouched by a promotion inside customer B.
        $this->assertTrue($aPrimary->fresh()->is_primary);
        $this->assertTrue($bContact->fresh()->is_primary);
    }

    #[Test]
    public function is_primary_cannot_be_mass_assigned(): void
    {
        // The invariant is a count across rows, so promotion must go through
        // the service. Mass assignment would route around it.
        $customer = Customer::factory()->create();

        $contact = new CustomerContact([
            'customer_id' => $customer->getKey(),
            'name' => 'X',
            'email' => 'x@example.test',
            'is_primary' => true,
        ]);

        $this->assertFalse((bool) $contact->is_primary);
    }

    #[Test]
    public function a_contact_cannot_be_created_without_a_customer(): void
    {
        $this->expectException(RuntimeException::class);

        CustomerContact::factory()->create(['customer_id' => null]);
    }

    #[Test]
    public function a_contacts_customer_can_never_be_reassigned(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $contact = CustomerContact::factory()->create(['customer_id' => $a->getKey()]);

        $this->expectException(RuntimeException::class);

        $contact->update(['customer_id' => $b->getKey()]);
    }

    #[Test]
    public function pairing_a_contact_with_another_customer_is_refused(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $contact = CustomerContact::factory()->create(['customer_id' => $a->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        $this->contacts->assertBelongsTo($contact, $b);
    }

    #[Test]
    public function queries_can_be_scoped_to_one_customer(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        CustomerContact::factory()->count(2)->create(['customer_id' => $a->getKey()]);
        CustomerContact::factory()->create(['customer_id' => $b->getKey()]);

        $this->assertSame(2, CustomerContact::forCustomer($a->getKey())->count());
        $this->assertSame(1, CustomerContact::forCustomer($b->getKey())->count());
    }

    #[Test]
    public function a_consent_change_is_always_audited(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $contact = $this->contacts->create($customer, ['name' => 'A', 'email' => 'a@example.test'], $actor);

        $this->contacts->updateConsent($contact, ['whatsapp_opt_in' => true], $actor);

        $this->assertTrue($contact->fresh()->whatsapp_opt_in);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::CustomerContactConsentChanged->value,
            'auditable_id' => $contact->getKey(),
        ]);
    }

    #[Test]
    public function an_archived_contact_is_retained_and_loses_primary_status(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $contact = $this->contacts->create($customer, ['name' => 'A', 'email' => 'a@example.test'], $actor, primary: true);

        $this->contacts->archive($contact, $actor);

        $this->assertDatabaseHas('customer_contacts', ['id' => $contact->getKey()]);
        $this->assertNotNull($contact->fresh()->archived_at);
        $this->assertFalse($contact->fresh()->is_primary);
    }
}
