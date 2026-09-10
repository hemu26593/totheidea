<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use App\Domain\Business\HrPolicyAcknowledgementService;
use App\Domain\Business\HrPolicyService;
use App\Domain\Business\PositionService;
use App\Domain\Shared\SubjectOwnership;
use App\Exceptions\CustomerIsolationException;
use App\Models\Customer;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer A against Customer B, across all three business systems.
 *
 * Every record here is reachable by an id in a request, and an org chart and a
 * set of HR policies are among the most sensitive things a business hands over.
 * So the question is asked deliberately and repetitively: can A read B, can A
 * write to B, and can A splice B's data into its own.
 *
 * Ownership is always resolved server-side from the customer or the policy the
 * caller actually passed - never from an id supplied alongside it.
 */
class BusinessSystemIsolationTest extends TestCase
{
    use RefreshDatabase;

    private PositionService $positions;

    private HrPolicyService $policies;

    private HrPolicyAcknowledgementService $acknowledgements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->positions = app(PositionService::class);
        $this->policies = app(HrPolicyService::class);
        $this->acknowledgements = app(HrPolicyAcknowledgementService::class);
    }

    /**
     * Two complete, unrelated businesses, each with a chart and a published
     * policy.
     *
     * @return array{0: Customer, 1: Position, 2: HrPolicy, 3: Customer, 4: Position, 5: HrPolicy}
     */
    private function twoBusinesses(): array
    {
        $out = [];

        foreach (['A', 'B'] as $label) {
            $customer = Customer::factory()->create();
            $position = $this->positions->create($customer, "{$label} Director", holderName: "{$label} Holder");
            $policy = $this->policies->draft($customer, "{$label} Leave Policy", "{$label} words");
            $this->policies->publish($policy, $this->admin());

            $out[] = $customer;
            $out[] = $position;
            $out[] = $policy->fresh();
        }

        return $out;
    }

    // --- Positions -------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_positions(): void
    {
        [$a, $aPosition, , $b] = $this->twoBusinesses();

        $chart = $this->positions->chartFor($a);

        $this->assertCount(1, $chart);
        $this->assertSame($aPosition->getKey(), $chart->first()->getKey());

        $scoped = Position::query()->forCustomer((int) $a->getKey())->get();
        $this->assertCount(1, $scoped);
        $this->assertNotContains((int) $b->getKey(), $scoped->pluck('customer_id')->all());
    }

    #[Test]
    public function customer_a_cannot_graft_its_chart_onto_customer_b(): void
    {
        [$a, , , , $bPosition] = $this->twoBusinesses();

        // Substituting B's position id as a parent must be refused on the
        // server, whatever the request claimed.
        $this->expectException(CustomerIsolationException::class);

        $this->positions->create($a, 'A Manager', $bPosition);
    }

    #[Test]
    public function customer_a_cannot_reparent_customer_b_positions(): void
    {
        [, $aPosition, , , $bPosition] = $this->twoBusinesses();

        $this->expectException(CustomerIsolationException::class);

        $this->positions->reparent($bPosition, $aPosition);
    }

    #[Test]
    public function a_position_cannot_be_reassigned_to_another_business(): void
    {
        [, $aPosition, , $b] = $this->twoBusinesses();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $aPosition->forceFill(['customer_id' => $b->getKey()])->save();
    }

    // --- HR policies ------------------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_policies(): void
    {
        [$a, , $aPolicy, $b] = $this->twoBusinesses();

        $library = $this->policies->libraryFor($a);

        $this->assertCount(1, $library);
        $this->assertSame($aPolicy->getKey(), $library->first()->getKey());
        $this->assertNotContains((int) $b->getKey(), $library->pluck('customer_id')->all());
    }

    #[Test]
    public function publishing_customer_b_policy_never_touches_customer_a(): void
    {
        [$a, , , $b] = $this->twoBusinesses();
        $bDraft = $this->policies->draft($b, 'B Second Policy');

        $this->policies->publish($bDraft, $this->admin());

        // A's library is unchanged - one policy, still its own.
        $library = $this->policies->libraryFor($a);
        $this->assertCount(1, $library);
        $this->assertSame((int) $a->getKey(), (int) $library->first()->customer_id);
    }

    #[Test]
    public function superseding_stays_inside_one_business(): void
    {
        [$a, , $aPolicy, $b] = $this->twoBusinesses();

        $replacement = $this->policies->supersede($aPolicy, 'A Leave Policy', 'A new words', 'v2');

        $this->assertSame((int) $a->getKey(), (int) $replacement->customer_id);
        $this->assertNotSame((int) $b->getKey(), (int) $replacement->customer_id);
        $this->assertCount(1, $this->policies->libraryFor($b));
    }

    #[Test]
    public function a_policy_cannot_be_reassigned_to_another_business(): void
    {
        [, , $aPolicy, $b] = $this->twoBusinesses();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $aPolicy->forceFill(['customer_id' => $b->getKey()])->save();
    }

    // --- Acknowledgements ---------------------------------------------------------

    #[Test]
    public function customer_a_staff_cannot_sign_customer_b_policy(): void
    {
        [, $aPosition, , , , $bPolicy] = $this->twoBusinesses();

        // Invariant I19, from A's side.
        $this->expectException(CustomerIsolationException::class);

        $this->acknowledgements->record($bPolicy, $aPosition, 'A Holder');
    }

    #[Test]
    public function customer_b_staff_cannot_sign_customer_a_policy(): void
    {
        [, , $aPolicy, , $bPosition] = $this->twoBusinesses();

        // And from B's side - the check is symmetric, not incidental.
        $this->expectException(CustomerIsolationException::class);

        $this->acknowledgements->record($aPolicy, $bPosition, 'B Holder');
    }

    #[Test]
    public function customer_a_cannot_read_customer_b_acknowledgements(): void
    {
        [, $aPosition, $aPolicy, , $bPosition, $bPolicy] = $this->twoBusinesses();

        $this->acknowledgements->record($aPolicy, $aPosition, 'A Holder');
        $this->acknowledgements->record($bPolicy, $bPosition, 'B Holder');

        $forA = $this->acknowledgements->forPolicy($aPolicy);

        $this->assertCount(1, $forA);
        $this->assertSame('A Holder', $forA->first()->acknowledged_name);
    }

    #[Test]
    public function the_compliance_tracker_never_mixes_two_businesses(): void
    {
        [$a, $aPosition, $aPolicy, , $bPosition, $bPolicy] = $this->twoBusinesses();

        // B has three more unsigned positions; none of them may appear in A's
        // outstanding list.
        Position::factory()->count(3)->create(['customer_id' => $bPolicy->customer_id]);

        $this->acknowledgements->record($aPolicy, $aPosition, 'A Holder');

        $compliance = $this->acknowledgements->complianceFor($aPolicy);

        $this->assertSame(1, $compliance['acknowledged']);
        $this->assertSame(0, $compliance['outstanding']);
        $this->assertSame([], $compliance['outstanding_positions']);
    }

    #[Test]
    public function an_acknowledgement_resolves_to_its_own_business(): void
    {
        [$a, $aPosition, $aPolicy, $b, $bPosition, $bPolicy] = $this->twoBusinesses();

        $aAck = $this->acknowledgements->record($aPolicy, $aPosition, 'A Holder');
        $bAck = $this->acknowledgements->record($bPolicy, $bPosition, 'B Holder');

        $ownership = app(SubjectOwnership::class);

        $this->assertSame((int) $a->getKey(), $ownership->customerIdFor($aAck));
        $this->assertSame((int) $b->getKey(), $ownership->customerIdFor($bAck));

        // And the resolver refuses to pair one with the other's business.
        $this->expectException(CustomerIsolationException::class);
        $ownership->assertBelongsTo($bAck, $a);
    }

    // --- All three at once -----------------------------------------------------------

    #[Test]
    public function nothing_of_customer_b_is_visible_from_customer_a(): void
    {
        [$a, , , , $bPosition, $bPolicy] = $this->twoBusinesses();

        $this->acknowledgements->record($bPolicy, $bPosition, 'B Holder');
        Position::factory()->count(2)->create(['customer_id' => $bPolicy->customer_id]);
        $this->policies->draft($bPolicy->customer()->first(), 'B Second Policy');

        // A sees only its own, in every one of the three systems.
        foreach ($this->positions->chartFor($a) as $position) {
            $this->assertSame((int) $a->getKey(), (int) $position->customer_id);
        }

        foreach ($this->policies->libraryFor($a) as $policy) {
            $this->assertSame((int) $a->getKey(), (int) $policy->customer_id);
        }

        $this->assertCount(1, $this->positions->chartFor($a));
        $this->assertCount(1, $this->policies->libraryFor($a));
        $this->assertSame(0, HrPolicyAcknowledgement::query()
            ->whereIn('hr_policy_id', HrPolicy::query()->where('customer_id', $a->getKey())->select('id'))
            ->count());
    }
}
