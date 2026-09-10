<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Customers\CustomerService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Customer != User, and customers are archived rather than deleted.
 *
 * These two rules are the reason the whole external-access design exists. If
 * either erodes, the architecture has silently become something else.
 */
class CustomerInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $customers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->customers = app(CustomerService::class);
    }

    // --- Customer != User -------------------------------------------------

    #[Test]
    public function a_customer_is_not_authenticatable(): void
    {
        $this->assertNotInstanceOf(Authenticatable::class, new Customer);
    }

    #[Test]
    public function a_customer_cannot_be_notified_directly(): void
    {
        // Reminders address customer_contacts. The business itself has no
        // inbox, which is what keeps consent per person and per channel.
        $this->assertFalse(method_exists(Customer::class, 'notify'));
    }

    #[Test]
    public function a_customer_exposes_no_credential_attribute(): void
    {
        $customer = Customer::factory()->create();

        foreach (['password', 'remember_token', 'email', 'is_active'] as $attribute) {
            $this->assertNull(
                $customer->getAttribute($attribute),
                "Customer must not carry [{$attribute}]."
            );
        }
    }

    #[Test]
    public function the_customer_model_declares_no_relationship_to_users_other_than_provenance(): void
    {
        $relations = collect((new ReflectionClass(Customer::class))->getMethods())
            ->filter(fn (\ReflectionMethod $m): bool => $m->class === Customer::class && $m->isPublic())
            ->map(fn (\ReflectionMethod $m): string => $m->getName())
            ->values()
            ->all();

        // archivedBy records which member of staff archived the record.
        // Anything else linking a customer to a user would make the business
        // an account, which the architecture forbids.
        $this->assertContains('archivedBy', $relations);

        foreach (['user', 'users', 'owner', 'account', 'login'] as $forbidden) {
            $this->assertNotContains($forbidden, $relations);
        }
    }

    #[Test]
    public function no_customer_role_exists(): void
    {
        $roles = Role::query()->pluck('name')->all();

        $this->assertSame(['super-admin', 'admin', 'staff'], array_values(array_intersect(
            ['super-admin', 'admin', 'staff'], $roles
        )));
        $this->assertNotContains('customer', $roles);
        $this->assertNotContains('consultant', $roles);
    }

    // --- Archive, never delete --------------------------------------------

    #[Test]
    public function deleting_a_customer_is_refused_by_the_model(): void
    {
        $customer = Customer::factory()->create();

        $this->expectException(RuntimeException::class);

        $customer->delete();
    }

    #[Test]
    public function the_customer_service_offers_no_delete_method(): void
    {
        $this->assertFalse(method_exists(CustomerService::class, 'delete'));
        $this->assertFalse(method_exists(CustomerService::class, 'destroy'));
        $this->assertFalse(method_exists(CustomerService::class, 'forceDelete'));
    }

    #[Test]
    public function archiving_preserves_the_record_and_records_who_did_it(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();

        $archived = $this->customers->archive($customer, $actor);

        $this->assertSame('archived', $archived->status);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame($actor->getKey(), $archived->archived_by);

        // Still there, still queryable.
        $this->assertDatabaseHas('customers', ['id' => $customer->getKey()]);
        $this->assertTrue($archived->isArchived());
    }

    #[Test]
    public function archiving_is_audited(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();

        $this->customers->archive($customer, $actor);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::CustomerArchived->value,
            'auditable_id' => $customer->getKey(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    #[Test]
    public function historical_data_survives_archiving(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create(['customer_id' => $customer->getKey()]);

        $this->customers->archive($customer, $actor);

        $this->assertDatabaseHas('customer_contacts', ['id' => $contact->getKey()]);
        $this->assertSame(1, $customer->fresh()->contacts()->count());
    }

    #[Test]
    public function creating_a_customer_is_audited(): void
    {
        $actor = $this->admin();

        $customer = $this->customers->create(['name' => 'Acme Ltd', 'code' => 'ACME-1'], $actor);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::CustomerCreated->value,
            'auditable_id' => $customer->getKey(),
        ]);

        $entry = AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->firstOrFail();
        $this->assertSame(ActorSource::InternalUser, $entry->source);
    }

    #[Test]
    public function a_user_and_a_customer_are_different_tables(): void
    {
        $customer = Customer::factory()->create(['name' => 'Shared Name']);
        $user = User::factory()->create(['name' => 'Shared Name']);

        $this->assertNotSame($customer->getTable(), $user->getTable());
        $this->assertSame('customers', $customer->getTable());
        $this->assertSame('users', $user->getTable());
    }
}
