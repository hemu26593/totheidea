<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiApprovalService;
use App\Domain\Ai\AiFormDraftService;
use App\Domain\Ai\AiGenerationService;
use App\Domain\Ai\CustomerContextAssembler;
use App\Enums\AiApprovalDecision;
use App\Enums\AiGenerationStatus;
use App\Enums\AiPurpose;
use App\Models\AiApproval;
use App\Models\AiGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * THE SELF-APPROVAL INVARIANT (I9, ADR-014).
 *
 * Whoever generated an AI artifact cannot approve it. Every enforcement point
 * is exercised separately here, because each one covers a path the others do
 * not: the policy covers Gate::authorize, the service covers programmatic
 * callers, and the model guard covers a direct write.
 *
 * The Super Admin cases are the ones that matter most. `approve` is listed in
 * authorization.guarded_abilities precisely so Gate::before does NOT
 * short-circuit the policy for a Super Admin - and even if that entry were
 * removed tomorrow, the model guard would still refuse, which is what makes
 * the rule absolute rather than merely intended.
 */
class AiSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function an_admin_cannot_approve_their_own_generation(): void
    {
        $admin = $this->admin();
        $generation = $this->awaitingApproval($admin);

        $this->assertFalse($admin->can('approve', $generation));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot decide on it/');

        app(AiApprovalService::class)->approve($generation, $admin);
    }

    #[Test]
    public function a_super_admin_cannot_approve_their_own_generation(): void
    {
        // The case Gate::before would otherwise wave through.
        $superAdmin = $this->superAdmin();
        $generation = $this->awaitingApproval($superAdmin);

        $this->assertFalse(
            $superAdmin->can('approve', $generation),
            'approve is a guarded ability; Gate::before must fall through to the policy.',
        );

        $this->expectException(RuntimeException::class);

        app(AiApprovalService::class)->approve($generation, $superAdmin);
    }

    #[Test]
    public function a_staff_member_cannot_approve_their_own_generation(): void
    {
        $staff = $this->staff();
        $generation = $this->awaitingApproval($staff);

        $this->assertFalse($staff->can('approve', $generation));

        $this->expectException(RuntimeException::class);

        app(AiApprovalService::class)->approve($generation, $staff);
    }

    #[Test]
    public function a_second_person_with_the_permission_may_approve(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $generation = $this->awaitingApproval($generator);

        $this->assertTrue($approver->can('approve', $generation));

        $approval = app(AiApprovalService::class)->approve($generation, $approver, 'Fine.');

        $this->assertSame(AiApprovalDecision::Approved, $approval->decision);
        $this->assertSame($approver->getKey(), $approval->decided_by);
        $this->assertSame(AiGenerationStatus::Approved, $generation->fresh()->status);
    }

    #[Test]
    public function a_second_person_without_the_permission_may_not(): void
    {
        // Not being the generator is necessary, not sufficient. Staff hold
        // only ai.analysis.view.
        $generator = $this->admin();
        $staff = $this->staff();
        $generation = $this->awaitingApproval($generator);

        $this->assertFalse($staff->can('approve', $generation));
        $this->assertFalse($staff->can('ai.forms.approve'));
    }

    #[Test]
    public function rejecting_your_own_generation_is_refused_exactly_as_approving_it_is(): void
    {
        // A generator marking their own output rejected is still one pair of
        // eyes, and still closes the record.
        $admin = $this->admin();
        $generation = $this->awaitingApproval($admin);

        $this->assertFalse($admin->can('reject', $generation));

        $this->expectException(RuntimeException::class);

        app(AiApprovalService::class)->reject($generation, $admin);
    }

    #[Test]
    public function the_model_refuses_a_self_approval_written_directly(): void
    {
        // Bypassing the service entirely. This is the guard that makes the
        // invariant absolute rather than a property of one call path.
        $superAdmin = $this->superAdmin();
        $generation = $this->awaitingApproval($superAdmin);

        $approval = new AiApproval;
        $approval->forceFill([
            'ai_generation_id' => $generation->getKey(),
            'decision' => AiApprovalDecision::Approved,
            'decided_by' => $superAdmin->getKey(),
            'decided_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot approve it/');

        $approval->save();
    }

    #[Test]
    public function the_model_refuses_when_the_generation_cannot_be_resolved(): void
    {
        // Fails closed. An unresolvable generation is the one case where
        // guessing would be indistinguishable from the rule being off.
        $approval = new AiApproval;
        $approval->forceFill([
            'ai_generation_id' => 999_999,
            'decision' => AiApprovalDecision::Approved,
            'decided_by' => $this->admin()->getKey(),
            'decided_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be resolved/');

        $approval->assertDeciderIsNotTheGenerator();
    }

    #[Test]
    public function approve_is_a_guarded_ability_in_configuration(): void
    {
        // The configuration entry this whole file depends on. If it were
        // removed, Gate::before would grant `approve` to a Super Admin before
        // AiGenerationPolicy was ever consulted.
        $this->assertContains('approve', (array) config('authorization.guarded_abilities'));
    }

    #[Test]
    public function a_super_admin_is_still_granted_unguarded_abilities(): void
    {
        // Proving the previous assertion is about `approve` specifically and
        // not about Super Admins being generally constrained.
        $superAdmin = $this->superAdmin();
        $generation = $this->awaitingApproval($this->admin());

        $this->assertTrue($superAdmin->can('view', $generation));
        $this->assertTrue($superAdmin->can('approve', $generation), 'Not their own generation.');
    }

    #[Test]
    public function an_approval_decision_cannot_be_amended_or_removed(): void
    {
        $generator = $this->admin();
        $approver = $this->admin();
        $generation = $this->awaitingApproval($generator);

        $approval = app(AiApprovalService::class)->approve($generation, $approver);

        try {
            $approval->forceFill(['remark' => 'actually, no'])->save();
            $this->fail('An approval decision must be immutable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $approval->delete();
            $this->fail('An approval decision must never be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }
    }

    /**
     * A generation that has drafted something and is waiting for a person.
     */
    private function awaitingApproval(User $generator): AiGeneration
    {
        $scenario = new AiScenario($generator);

        AiScenario::bindProvider(AiScenario::validFormProposal());

        $generation = app(AiGenerationService::class)->generate(
            customer: $scenario->customer,
            prompt: $scenario->prompt,
            purpose: AiPurpose::FormDraft,
            context: app(CustomerContextAssembler::class)
                ->forFormDraft($scenario->customer, 'A short operations intake.'),
            actor: $generator,
            placeholders: ['business_name' => $scenario->customer->name],
        );

        app(AiFormDraftService::class)->draftFrom($generation, $scenario->template);

        return $generation->fresh();
    }
}
