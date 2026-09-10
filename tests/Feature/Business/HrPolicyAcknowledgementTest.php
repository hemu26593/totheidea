<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use App\Domain\Business\HrPolicyAcknowledgementService;
use App\Domain\Business\HrPolicyService;
use App\Domain\Shared\SubjectOwnership;
use App\Enums\ActorSource;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Sign-offs.
 *
 * A sign-off is evidence. Everything here defends two properties: that the
 * person signing is identified by a POSITION rather than an account, and that
 * what they signed cannot be altered afterwards.
 */
class HrPolicyAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private HrPolicyAcknowledgementService $acknowledgements;

    private HrPolicyService $policies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->acknowledgements = app(HrPolicyAcknowledgementService::class);
        $this->policies = app(HrPolicyService::class);
    }

    /**
     * A business with a published policy and someone who can sign it.
     *
     * @return array{0: Customer, 1: HrPolicy, 2: Position}
     */
    private function businessWithPublishedPolicy(): array
    {
        $customer = Customer::factory()->create();
        $policy = $this->policies->draft($customer, 'Leave Policy', 'Twelve days a year.', 'v1');
        $this->policies->publish($policy, $this->admin());
        $position = Position::factory()->create(['customer_id' => $customer->getKey()]);

        return [$customer, $policy->fresh(), $position];
    }

    // --- Recording a sign-off ---------------------------------------------------

    #[Test]
    public function a_named_person_signs_off_on_a_published_policy(): void
    {
        [$customer, $policy, $position] = $this->businessWithPublishedPolicy();
        $actor = $this->admin();

        $acknowledgement = $this->acknowledgements->record($policy, $position, 'Priya Shah', $actor);

        $this->assertSame($policy->getKey(), $acknowledgement->hr_policy_id);
        $this->assertSame($position->getKey(), $acknowledgement->position_id);
        $this->assertSame('Priya Shah', $acknowledgement->acknowledged_name);
        $this->assertNotNull($acknowledgement->acknowledged_at);

        $log = AuditLog::query()->where('action', AuditAction::HrPolicyAcknowledged)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Priya Shah', $log->new_values['acknowledged_name']);
        $this->assertSame((int) $customer->getKey(), (int) $log->new_values['customer_id']);
    }

    #[Test]
    public function who_signed_is_a_position_not_a_user(): void
    {
        // The participant's staff have no platform account, so there is no
        // user_id here and never will be.
        $this->assertTrue(Schema::hasColumn('hr_policy_acknowledgements', 'position_id'));
        $this->assertFalse(Schema::hasColumn('hr_policy_acknowledgements', 'user_id'));

        $userFks = collect(Schema::getForeignKeys('hr_policy_acknowledgements'))
            ->filter(fn (array $fk): bool => $fk['foreign_table'] === 'users')
            ->flatMap(fn (array $fk): array => $fk['columns'])
            ->values()
            ->all();

        // created_by is the actor triple - who keyed it in - not who signed.
        $this->assertSame(['created_by'], $userFks);
    }

    #[Test]
    public function a_sign_off_needs_a_name(): void
    {
        [, $policy, $position] = $this->businessWithPublishedPolicy();

        $this->expectException(InvalidArgumentException::class);

        $this->acknowledgements->record($policy, $position, '   ');
    }

    #[Test]
    public function a_draft_cannot_be_acknowledged(): void
    {
        $customer = Customer::factory()->create();
        $draft = $this->policies->draft($customer, 'Not yet in force');
        $position = Position::factory()->create(['customer_id' => $customer->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only a published policy can be acknowledged');

        $this->acknowledgements->record($draft, $position, 'Priya Shah');
    }

    #[Test]
    public function a_superseded_policy_collects_no_new_sign_offs(): void
    {
        [$customer, $policy, $position] = $this->businessWithPublishedPolicy();
        $this->policies->supersede($policy, 'Leave Policy', 'New words', 'v2');

        $newStarter = Position::factory()->create(['customer_id' => $customer->getKey()]);

        // Those who signed keep their sign-off; nobody new signs an
        // out-of-force version.
        $this->expectException(RuntimeException::class);

        $this->acknowledgements->record($policy->fresh(), $newStarter, 'Ravi Patel');
    }

    #[Test]
    public function an_archived_position_cannot_sign(): void
    {
        [$customer, $policy] = $this->businessWithPublishedPolicy();
        $retired = Position::factory()->archived()->create(['customer_id' => $customer->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archived and cannot sign');

        $this->acknowledgements->record($policy, $retired, 'Someone');
    }

    // --- One per position per policy ----------------------------------------------

    #[Test]
    public function a_position_signs_a_policy_once(): void
    {
        [, $policy, $position] = $this->businessWithPublishedPolicy();
        $this->acknowledgements->record($policy, $position, 'Priya Shah');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has already acknowledged');

        $this->acknowledgements->record($policy, $position, 'Priya Shah');
    }

    #[Test]
    public function the_duplicate_rule_is_a_database_constraint(): void
    {
        // Duplicates would inflate the compliance tracker, which is the one
        // number this table exists to produce.
        [, $policy, $position] = $this->businessWithPublishedPolicy();
        $this->acknowledgements->record($policy, $position, 'Priya Shah');

        $this->expectException(QueryException::class);

        HrPolicyAcknowledgement::factory()->create([
            'hr_policy_id' => $policy->getKey(),
            'position_id' => $position->getKey(),
        ]);
    }

    #[Test]
    public function re_acknowledging_belongs_to_the_new_version(): void
    {
        [$customer, $v1, $position] = $this->businessWithPublishedPolicy();
        $this->acknowledgements->record($v1, $position, 'Priya Shah');

        $v2 = $this->policies->supersede($v1->fresh(), 'Leave Policy', 'Eighteen days a year.', 'v2');
        $this->policies->publish($v2, $this->admin());

        $second = $this->acknowledgements->record($v2->fresh(), $position, 'Priya Shah');

        // Two sign-offs, each bound to the words it was given.
        $this->assertSame(2, HrPolicyAcknowledgement::query()->count());
        $this->assertSame($v2->getKey(), $second->hr_policy_id);
        $this->assertSame('Twelve days a year.', $v1->fresh()->body);
        $this->assertSame('Eighteen days a year.', $v2->fresh()->body);
    }

    // --- Immutability ------------------------------------------------------------------

    #[Test]
    public function a_sign_off_cannot_be_edited(): void
    {
        $acknowledgement = HrPolicyAcknowledgement::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $acknowledgement->forceFill(['acknowledged_name' => 'Someone else'])->save();
    }

    #[Test]
    public function a_sign_off_cannot_be_deleted(): void
    {
        $acknowledgement = HrPolicyAcknowledgement::factory()->create();

        $this->expectException(RuntimeException::class);
        $acknowledgement->delete();
    }

    #[Test]
    public function not_even_a_super_admin_can_rewrite_a_sign_off(): void
    {
        // 'update' is not guarded, so Gate::before grants it and the policy is
        // never consulted. The guard is on the model for that reason.
        $acknowledgement = HrPolicyAcknowledgement::factory()->create();
        $superAdmin = $this->superAdmin();

        // Gate::before grants it - which is precisely why the policy's
        // refusal is not the guarantee.
        $this->assertTrue($superAdmin->can('update', $acknowledgement));
        $this->actingAs($superAdmin);

        $this->expectException(RuntimeException::class);
        $acknowledgement->forceFill(['acknowledged_name' => 'Rewritten'])->save();
    }

    // --- Invariant I19 -------------------------------------------------------------------

    #[Test]
    public function a_position_cannot_sign_another_businesss_policy(): void
    {
        [, $policy] = $this->businessWithPublishedPolicy();
        $outsider = Position::factory()->create(); // its own customer

        $this->expectException(CustomerIsolationException::class);

        $this->acknowledgements->record($policy, $outsider, 'Not their policy');
    }

    #[Test]
    public function the_same_business_check_is_reported_as_a_customer_mismatch(): void
    {
        [, $policy] = $this->businessWithPublishedPolicy();
        $outsider = Position::factory()->create();

        try {
            $this->acknowledgements->assertSameBusiness($policy, $outsider);
            $this->fail('Expected the cross-business pairing to be refused.');
        } catch (CustomerIsolationException $e) {
            $this->assertStringContainsString('customer', $e->getMessage());
        }
    }

    #[Test]
    public function nothing_is_written_when_the_pairing_is_refused(): void
    {
        [, $policy] = $this->businessWithPublishedPolicy();
        $outsider = Position::factory()->create();
        $before = HrPolicyAcknowledgement::query()->count();

        try {
            $this->acknowledgements->record($policy, $outsider, 'Not their policy');
            $this->fail('Expected the sign-off to be refused.');
        } catch (CustomerIsolationException) {
            // expected
        }

        $this->assertSame($before, HrPolicyAcknowledgement::query()->count());
    }

    // --- External sign-off uses the existing architecture ---------------------------------

    #[Test]
    public function an_external_sign_off_carries_the_grant_and_creates_no_account(): void
    {
        [$customer, $policy, $position] = $this->businessWithPublishedPolicy();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $grant = AccessGrant::factory()->create([
            'customer_id' => $customer->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'ability' => GrantAbility::AcceptTerms,
        ]);
        $usersBefore = User::query()->count();

        $acknowledgement = $this->acknowledgements->record($policy, $position, 'Priya Shah', grant: $grant);

        $this->assertSame(ActorSource::ExternalGrant, $acknowledgement->source);
        $this->assertSame($grant->getKey(), $acknowledgement->access_grant_id);
        $this->assertNull($acknowledgement->created_by);

        // No account, no session, no second access mechanism.
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function a_grant_for_another_business_cannot_sign(): void
    {
        [, $policy, $position] = $this->businessWithPublishedPolicy();
        $foreignGrant = AccessGrant::factory()->create(['ability' => GrantAbility::AcceptTerms]);

        $this->expectException(CustomerIsolationException::class);

        $this->acknowledgements->record($policy, $position, 'Priya Shah', grant: $foreignGrant);
    }

    // --- The compliance tracker -------------------------------------------------------------

    #[Test]
    public function the_tracker_is_derived_not_stored(): void
    {
        [$customer, $policy, $signed] = $this->businessWithPublishedPolicy();
        $unsigned = Position::factory()->create(['customer_id' => $customer->getKey()]);

        $this->acknowledgements->record($policy, $signed, 'Priya Shah');

        $compliance = $this->acknowledgements->complianceFor($policy);

        $this->assertSame(1, $compliance['acknowledged']);
        $this->assertSame(1, $compliance['outstanding']);
        $this->assertSame([$unsigned->getKey()], $compliance['outstanding_positions']);

        // Archiving the outstanding position changes the answer immediately,
        // which is only possible because nothing was cached.
        $unsigned->forceFill(['archived_at' => now()])->save();
        $this->assertSame(0, $this->acknowledgements->complianceFor($policy)['outstanding']);
    }

    #[Test]
    public function the_tracker_never_counts_another_businesss_positions(): void
    {
        [$customer, $policy, $position] = $this->businessWithPublishedPolicy();
        Position::factory()->count(3)->create(); // three other businesses

        $this->acknowledgements->record($policy, $position, 'Priya Shah');

        $compliance = $this->acknowledgements->complianceFor($policy);

        $this->assertSame(1, $compliance['acknowledged']);
        $this->assertSame(0, $compliance['outstanding']);
    }

    // --- Isolation resolves through the policy -------------------------------------------------

    #[Test]
    public function ownership_is_resolved_through_the_policy(): void
    {
        // An acknowledgement carries neither a customer nor an enrolment. The
        // shared resolver reaches its owner through the policy it signs.
        [$customer, $policy, $position] = $this->businessWithPublishedPolicy();
        $acknowledgement = $this->acknowledgements->record($policy, $position, 'Priya Shah');

        $this->assertSame(
            (int) $customer->getKey(),
            app(SubjectOwnership::class)->customerIdFor($acknowledgement),
        );
    }

    #[Test]
    public function an_acknowledgement_with_no_resolvable_policy_fails_closed(): void
    {
        // A row whose policy cannot be resolved - the kind of drift the
        // resolver must survive rather than assume safe. Built in memory
        // because the foreign key rightly forbids persisting one.
        $orphan = new HrPolicyAcknowledgement;
        $orphan->forceFill([
            'hr_policy_id' => 999999,
            'position_id' => Position::factory()->create()->getKey(),
            'acknowledged_name' => 'Nobody',
            'acknowledged_at' => now(),
        ]);

        $this->expectException(CustomerIsolationException::class);

        app(SubjectOwnership::class)->customerIdFor($orphan);
    }
}
