<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Access\AccessGrantService;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Access grants are the only unauthenticated write path the system will have.
 *
 * Phase 1 builds the grant infrastructure; redemption is Phase 5. These tests
 * pin the properties that make redemption safe to build later: the plaintext
 * token is never stored, and a grant cannot be widened by substituting an id.
 */
class AccessGrantTest extends TestCase
{
    use RefreshDatabase;

    private AccessGrantService $grants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->grants = app(AccessGrantService::class);
    }

    /**
     * @return array{0: Customer, 1: Enrollment}
     */
    private function customerWithEnrollment(): array
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        return [$customer, $enrollment];
    }

    // --- Token handling ---------------------------------------------------

    #[Test]
    public function only_the_token_hash_is_persisted(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();

        $issued = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(),
        );

        $this->assertSame(
            hash('sha256', $issued->plaintextToken),
            $issued->grant->token_hash,
        );

        // The plaintext appears nowhere in the row.
        $row = (array) DB::table('access_grants')
            ->where('id', $issued->grant->getKey())->first();

        foreach ($row as $column => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString(
                    $issued->plaintextToken,
                    $value,
                    "The plaintext token must not appear in [{$column}]."
                );
            }
        }
    }

    #[Test]
    public function the_token_hash_is_hidden_from_serialisation(): void
    {
        $grant = AccessGrant::factory()->create();

        $this->assertArrayNotHasKey('token_hash', $grant->toArray());
    }

    #[Test]
    public function a_grant_is_found_by_hash_not_by_plaintext(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();

        $issued = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(),
        );

        $this->assertTrue($this->grants->findByToken($issued->plaintextToken)?->is($issued->grant));
        $this->assertNull($this->grants->findByToken('not-the-token'));
    }

    #[Test]
    public function issued_tokens_are_unique_and_high_entropy(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();
        $actor = $this->admin();

        $tokens = collect(range(1, 5))->map(fn (): string => $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        )->plaintextToken);

        $this->assertCount(5, $tokens->unique());
        // 32 random bytes, base64url encoded.
        $this->assertGreaterThanOrEqual(40, strlen($tokens->first()));
    }

    // --- Scope ------------------------------------------------------------

    #[Test]
    public function a_grant_records_its_full_scope(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();
        $expiry = now()->addDays(3);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, $expiry, $this->admin(),
        )->grant;

        $this->assertSame($customer->getKey(), $grant->customer_id);
        $this->assertSame($enrollment->getKey(), $grant->enrollment_id);
        $this->assertSame(GrantAbility::AcceptTerms, $grant->ability);
        $this->assertSame($enrollment->getMorphClass(), $grant->subject_type);
        $this->assertSame($enrollment->getKey(), (int) $grant->subject_id);
        $this->assertNotNull($grant->expires_at);
    }

    #[Test]
    public function a_grant_for_another_customers_enrolment_is_refused(): void
    {
        [$a, $aEnrollment] = $this->customerWithEnrollment();
        $b = Customer::factory()->create();

        $this->expectException(CustomerIsolationException::class);

        // Customer B named, but customer A's enrolment supplied.
        $this->grants->issue(
            $b, $aEnrollment, $aEnrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(),
        );
    }

    #[Test]
    public function a_grant_whose_subject_belongs_to_another_enrolment_is_refused(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();
        $otherEnrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        // Same customer, but the subject is a different enrolment - the grant
        // must not be widened to it.
        $this->grants->issue(
            $customer, $enrollment, $otherEnrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(),
        );
    }

    #[Test]
    public function a_grant_for_another_customers_contact_is_refused(): void
    {
        [$a, $aEnrollment] = $this->customerWithEnrollment();
        $bContact = CustomerContact::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
        ]);

        $this->expectException(CustomerIsolationException::class);

        $this->grants->issue(
            $a, $aEnrollment, $aEnrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(),
            contact: $bContact,
        );
    }

    #[Test]
    public function a_subject_whose_ownership_cannot_be_verified_is_refused(): void
    {
        // Fails CLOSED. A later phase adding a new subject type must extend
        // the check deliberately rather than inherit a silent pass.
        [$customer, $enrollment] = $this->customerWithEnrollment();

        $this->expectException(CustomerIsolationException::class);

        $this->grants->issue(
            $customer, $enrollment, $this->admin(),
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(),
        );
    }

    // --- Validity ---------------------------------------------------------

    #[Test]
    public function a_grant_cannot_be_issued_already_expired(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();

        $this->expectException(InvalidArgumentException::class);

        $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->subDay(), $this->admin(),
        );
    }

    #[Test]
    public function a_grant_must_permit_at_least_one_use(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();

        $this->expectException(InvalidArgumentException::class);

        $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $this->admin(), maxUses: 0,
        );
    }

    #[Test]
    public function an_expired_grant_is_not_valid(): void
    {
        $this->assertFalse(AccessGrant::factory()->expired()->create()->isValid());
    }

    #[Test]
    public function a_revoked_grant_is_not_valid(): void
    {
        $this->assertFalse(AccessGrant::factory()->revoked()->create()->isValid());
    }

    #[Test]
    public function an_exhausted_grant_is_not_valid(): void
    {
        $this->assertFalse(AccessGrant::factory()->exhausted()->create()->isValid());
    }

    #[Test]
    public function a_fresh_grant_is_valid(): void
    {
        $this->assertTrue(AccessGrant::factory()->create()->isValid());
    }

    #[Test]
    public function revocation_is_state_not_deletion(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();
        $actor = $this->admin();

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        )->grant;

        $revoked = $this->grants->revoke($grant, $actor, 'No longer required');

        $this->assertDatabaseHas('access_grants', ['id' => $grant->getKey()]);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame($actor->getKey(), $revoked->revoked_by);
        $this->assertSame('No longer required', $revoked->revoke_reason);
        $this->assertFalse($revoked->isValid());
    }

    // --- Audit ------------------------------------------------------------

    #[Test]
    public function issuing_and_revoking_are_audited_without_the_token(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();
        $actor = $this->admin();

        $issued = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        );
        $this->grants->revoke($issued->grant, $actor);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AccessGrantIssued->value,
            'auditable_id' => $issued->grant->getKey(),
            'actor_id' => $actor->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AccessGrantRevoked->value,
            'auditable_id' => $issued->grant->getKey(),
        ]);

        // The plaintext token must never reach the audit trail.
        foreach (AuditLog::all() as $entry) {
            $this->assertStringNotContainsString(
                $issued->plaintextToken,
                json_encode($entry->toArray(), JSON_THROW_ON_ERROR),
            );
        }
    }

    #[Test]
    public function grant_lifecycle_events_are_security_events(): void
    {
        foreach ([
            AuditAction::AccessGrantIssued,
            AuditAction::AccessGrantUsed,
            AuditAction::AccessGrantRevoked,
        ] as $action) {
            $this->assertTrue($action->isSecurityEvent());
        }
    }

    #[Test]
    public function redemption_is_not_implemented_in_this_phase(): void
    {
        // Phase 5 owns redemption. Nothing here may consume a token.
        foreach (['redeem', 'consume', 'use', 'authenticate', 'login'] as $method) {
            $this->assertFalse(
                method_exists(AccessGrantService::class, $method),
                "AccessGrantService::{$method}() belongs to Phase 5."
            );
        }
    }

    #[Test]
    public function a_grant_creates_no_account_and_no_session(): void
    {
        [$customer, $enrollment] = $this->customerWithEnrollment();
        $actor = $this->admin();

        // Counted after the actor exists, so this measures what ISSUING
        // creates - which must be nothing.
        $before = User::query()->count();

        $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        );

        $this->assertSame($before, User::query()->count(), 'A grant must never create a user.');
        $this->assertGuest();
    }
}
