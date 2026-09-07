<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Access\AccessGrantService;
use App\Domain\Access\TermsAcceptanceService;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\TermsAcceptance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The legal record that terms were accepted, with name, date and time.
 *
 * Evidence that can be edited afterwards is not evidence, so immutability is
 * tested at the model, not merely documented.
 */
class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private TermsAcceptanceService $terms;

    private AccessGrantService $grants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->terms = app(TermsAcceptanceService::class);
        $this->grants = app(AccessGrantService::class);
    }

    #[Test]
    public function staff_can_record_an_acceptance_on_the_customers_behalf(): void
    {
        $actor = $this->admin();
        $enrollment = Enrollment::factory()->create();

        $acceptance = $this->terms->recordByStaff($enrollment, 'v1', 'Raajul Parekh', $actor);

        $this->assertSame('Raajul Parekh', $acceptance->accepted_name);
        $this->assertSame(ActorSource::InternalUser, $acceptance->source);
        $this->assertSame($actor->getKey(), $acceptance->created_by);
        $this->assertNull($acceptance->access_grant_id);
        $this->assertNotNull($acceptance->accepted_at);
    }

    #[Test]
    public function an_acceptance_through_a_grant_records_the_grant_not_a_user(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        )->grant;

        $acceptance = $this->terms->recordByGrant($enrollment, 'v1', 'Raajul Parekh', $grant);

        $this->assertSame(ActorSource::ExternalGrant, $acceptance->source);
        $this->assertSame($grant->getKey(), $acceptance->access_grant_id);
        // No user: the acceptor has no account, which is the whole point.
        $this->assertNull($acceptance->created_by);
    }

    #[Test]
    public function a_grant_from_another_enrolment_cannot_accept_these_terms(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $other = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = $this->grants->issue(
            $customer, $other, $other,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        )->grant;

        $this->expectException(CustomerIsolationException::class);

        $this->terms->recordByGrant($enrollment, 'v1', 'Someone', $grant);
    }

    #[Test]
    public function an_acceptance_is_immutable(): void
    {
        $acceptance = TermsAcceptance::factory()->create();

        $this->expectException(RuntimeException::class);

        $acceptance->update(['accepted_name' => 'Someone Else']);
    }

    #[Test]
    public function an_acceptance_cannot_be_deleted(): void
    {
        $acceptance = TermsAcceptance::factory()->create();

        $this->expectException(RuntimeException::class);

        $acceptance->delete();
    }

    #[Test]
    public function one_acceptance_per_enrolment_per_terms_version(): void
    {
        $actor = $this->admin();
        $enrollment = Enrollment::factory()->create();

        $this->terms->recordByStaff($enrollment, 'v1', 'A', $actor);

        $this->expectException(QueryException::class);

        $this->terms->recordByStaff($enrollment, 'v1', 'A', $actor);
    }

    #[Test]
    public function a_reissued_terms_version_produces_a_second_record_and_keeps_the_first(): void
    {
        $actor = $this->admin();
        $enrollment = Enrollment::factory()->create();

        $first = $this->terms->recordByStaff($enrollment, 'v1', 'A', $actor);
        $second = $this->terms->recordByStaff($enrollment, 'v2', 'A', $actor);

        $this->assertSame(2, $enrollment->termsAcceptances()->count());
        $this->assertDatabaseHas('terms_acceptances', ['id' => $first->getKey(), 'terms_version' => 'v1']);
        $this->assertDatabaseHas('terms_acceptances', ['id' => $second->getKey(), 'terms_version' => 'v2']);
    }

    #[Test]
    public function the_actor_triple_is_coherent_on_every_acceptance(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        )->grant;

        $internal = $this->terms->recordByStaff($enrollment, 'v1', 'A', $actor);
        $external = $this->terms->recordByGrant($enrollment, 'v2', 'B', $grant);

        // Exactly one of created_by / access_grant_id on each.
        $this->assertNotNull($internal->created_by);
        $this->assertNull($internal->access_grant_id);
        $this->assertNull($external->created_by);
        $this->assertNotNull($external->access_grant_id);
    }

    #[Test]
    public function an_incoherent_actor_triple_is_refused(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->expectException(RuntimeException::class);

        TermsAcceptance::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'source' => ActorSource::InternalUser,
            'created_by' => null,
        ]);
    }

    #[Test]
    public function acceptance_is_audited_with_the_correct_source(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::AcceptTerms, now()->addDay(), $actor,
        )->grant;

        $this->terms->recordByGrant($enrollment, 'v1', 'Raajul', $grant);

        $entry = AuditLog::query()->where('action', AuditAction::TermsAccepted->value)->firstOrFail();

        $this->assertSame(ActorSource::ExternalGrant, $entry->source);
        $this->assertSame($grant->getKey(), $entry->access_grant_id);
        $this->assertNull($entry->actor_id);
    }
}
