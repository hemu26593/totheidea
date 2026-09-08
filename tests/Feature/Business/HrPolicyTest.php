<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use App\Domain\Business\HrPolicyAcknowledgementService;
use App\Domain\Business\HrPolicyService;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\HrPolicy;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The HR policy library.
 *
 * The load-bearing rule is that a published policy's words are frozen.
 * Acknowledgements are people attesting to specific text, so editing that text
 * afterwards would make historical sign-offs attest to words nobody read. A
 * revision is a new policy that supersedes the old.
 */
class HrPolicyTest extends TestCase
{
    use RefreshDatabase;

    private HrPolicyService $policies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->policies = app(HrPolicyService::class);
    }

    // --- Drafting -------------------------------------------------------------

    #[Test]
    public function a_policy_starts_as_a_draft(): void
    {
        $customer = Customer::factory()->create();

        $policy = $this->policies->draft(
            $customer, 'Leave Policy', 'Twelve days a year.', 'v1', $this->admin(),
        );

        $this->assertSame('Leave Policy', $policy->title);
        $this->assertSame('Twelve days a year.', $policy->body);
        $this->assertSame('v1', $policy->version_label);
        $this->assertTrue($policy->isDraft());
        $this->assertNull($policy->published_at);
        $this->assertSame((int) $customer->getKey(), (int) $policy->customer_id);
    }

    #[Test]
    public function a_policy_needs_a_title(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policies->draft(Customer::factory()->create(), '  ');
    }

    #[Test]
    public function the_three_statuses_are_the_only_ones(): void
    {
        $this->assertSame(['draft', 'published', 'superseded'], HrPolicy::STATUSES);
    }

    #[Test]
    public function a_draft_is_freely_edited(): void
    {
        $policy = $this->policies->draft(Customer::factory()->create(), 'Draft', 'First words');

        $edited = $this->policies->updateDraft($policy, [
            'title' => 'Leave Policy',
            'body' => 'Better words',
        ]);

        $this->assertSame('Leave Policy', $edited->title);
        $this->assertSame('Better words', $edited->body);
    }

    // --- Publication is privileged ----------------------------------------------

    #[Test]
    public function admin_can_publish(): void
    {
        $policy = $this->policies->draft(Customer::factory()->create(), 'Leave Policy', 'Words');
        $admin = $this->admin();

        $published = $this->policies->publish($policy, $admin);

        $this->assertTrue($published->isPublished());
        $this->assertNotNull($published->published_at);

        $log = AuditLog::query()->where('action', AuditAction::HrPolicyPublished)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame('Leave Policy', $log->new_values['title']);
    }

    #[Test]
    public function staff_cannot_publish(): void
    {
        $policy = $this->policies->draft(Customer::factory()->create(), 'Leave Policy', 'Words');
        $staff = $this->staff();

        // Staff drafts; Admin puts in force. That separation is the whole
        // point of a separate permission.
        $this->assertTrue($staff->can('hr_policies.manage'));
        $this->assertFalse($staff->can('hr_policies.publish'));
        $this->assertFalse($staff->can('publish', $policy));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hr_policies.publish is required');

        $this->policies->publish($policy, $staff);
    }

    #[Test]
    public function the_service_refuses_even_if_a_caller_forgets_to_authorize(): void
    {
        // The refusal is in the service as well as the policy, so no job,
        // command or future controller can publish by omission.
        $policy = $this->policies->draft(Customer::factory()->create(), 'Leave Policy');
        $staff = $this->staff();

        try {
            $this->policies->publish($policy, $staff);
            $this->fail('Expected publication to be refused.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue($policy->fresh()->isDraft());
        $this->assertNull($policy->fresh()->published_at);
    }

    #[Test]
    public function a_super_admin_may_publish_because_publish_is_not_a_self_protection_ability(): void
    {
        // Gate::before grants a Super Admin every ability outside
        // authorization.guarded_abilities. hr_policies.publish is a capability,
        // not a self-protection safeguard, so it is deliberately NOT guarded -
        // and a Super Admin holds it through the seeded matrix as well.
        $policy = $this->policies->draft(Customer::factory()->create(), 'Leave Policy');
        $superAdmin = $this->superAdmin();

        $this->assertNotContains('publish', config('authorization.guarded_abilities'));
        $this->assertTrue($superAdmin->can('publish', $policy));

        $published = $this->policies->publish($policy, $superAdmin);
        $this->assertTrue($published->isPublished());
    }

    #[Test]
    public function only_a_draft_can_be_published(): void
    {
        $policy = HrPolicy::factory()->published()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only a draft can be published');

        $this->policies->publish($policy, $this->admin());
    }

    // --- Published text is frozen -------------------------------------------------

    #[Test]
    public function a_published_policy_cannot_be_edited_through_the_service(): void
    {
        $policy = $this->policies->draft(Customer::factory()->create(), 'Leave Policy', 'Words');
        $this->policies->publish($policy, $this->admin());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('its text is frozen');

        $this->policies->updateDraft($policy->fresh(), ['body' => 'Different words']);
    }

    #[Test]
    public function a_published_policys_words_cannot_be_changed_at_all(): void
    {
        $policy = HrPolicy::factory()->published()->create(['body' => 'The words that were signed']);

        foreach (HrPolicy::CONTENT_COLUMNS as $column) {
            try {
                $policy->fresh()->forceFill([$column => 'Rewritten'])->save();
                $this->fail("[{$column}] should be frozen on a published policy.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
        }

        $this->assertSame('The words that were signed', $policy->fresh()->body);
    }

    #[Test]
    public function not_even_a_super_admin_can_rewrite_a_published_policy(): void
    {
        // 'update' is not a guarded ability, so Gate::before grants it and the
        // policy class is never consulted. The guarantee lives on the model
        // for exactly that reason.
        $policy = HrPolicy::factory()->published()->create();
        $superAdmin = $this->superAdmin();

        $this->assertTrue($superAdmin->can('update', $policy));
        $this->actingAs($superAdmin);

        $this->expectException(RuntimeException::class);
        $policy->forceFill(['body' => 'Rewritten by a super admin'])->save();
    }

    #[Test]
    public function what_became_of_a_policy_may_still_be_recorded(): void
    {
        // Its status, its supersession link and its archived_at describe what
        // HAPPENED to the policy - only what it SAID is frozen.
        $policy = HrPolicy::factory()->published()->create();

        $policy->forceFill(['archived_at' => now()])->save();

        $this->assertNotNull($policy->fresh()->archived_at);
        $this->assertTrue($policy->fresh()->isPublished());
    }

    #[Test]
    public function a_policy_is_never_deleted(): void
    {
        $policy = HrPolicy::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never deleted');

        $policy->delete();
    }

    // --- Supersession is the versioning -----------------------------------------------

    #[Test]
    public function superseding_leaves_the_old_text_untouched(): void
    {
        $customer = Customer::factory()->create();
        $v1 = $this->policies->draft($customer, 'Leave Policy', 'Twelve days a year.', 'v1');
        $this->policies->publish($v1, $this->admin());

        $v2 = $this->policies->supersede($v1->fresh(), 'Leave Policy', 'Eighteen days a year.', 'v2');

        $this->assertSame('Twelve days a year.', $v1->fresh()->body);
        $this->assertTrue($v1->fresh()->isSuperseded());
        $this->assertSame($v2->getKey(), $v1->fresh()->superseded_by_id);

        // The replacement starts as a draft: preparing a revision is not the
        // same act as putting it in force.
        $this->assertTrue($v2->isDraft());
        $this->assertSame('Eighteen days a year.', $v2->body);
    }

    #[Test]
    public function only_a_published_policy_is_superseded(): void
    {
        $policy = $this->policies->draft(Customer::factory()->create(), 'Draft');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only a published policy is superseded');

        $this->policies->supersede($policy, 'Revision');
    }

    #[Test]
    public function the_supersession_chain_reads_in_order(): void
    {
        $customer = Customer::factory()->create();
        $admin = $this->admin();

        $v1 = $this->policies->draft($customer, 'Leave Policy', 'One', 'v1');
        $this->policies->publish($v1, $admin);

        $v2 = $this->policies->supersede($v1->fresh(), 'Leave Policy', 'Two', 'v2');
        $this->policies->publish($v2, $admin);

        $v3 = $this->policies->supersede($v2->fresh(), 'Leave Policy', 'Three', 'v3');

        $chain = $this->policies->history($v3->fresh());

        $this->assertSame(['v1', 'v2', 'v3'], array_map(
            fn (HrPolicy $p): string => (string) $p->version_label,
            $chain,
        ));
    }

    #[Test]
    public function a_replacement_stays_within_the_same_business(): void
    {
        $customer = Customer::factory()->create();
        $v1 = $this->policies->draft($customer, 'Leave Policy', 'One');
        $this->policies->publish($v1, $this->admin());

        $v2 = $this->policies->supersede($v1->fresh(), 'Leave Policy', 'Two');

        $this->assertSame((int) $customer->getKey(), (int) $v2->customer_id);
    }

    #[Test]
    public function there_is_no_second_document_or_versioning_system(): void
    {
        // Text lives in body; files go to the existing documents table;
        // supersession IS the versioning.
        foreach ([
            'hr_policy_versions', 'hr_policy_documents', 'hr_policy_revisions',
            'policy_files', 'hr_documents',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasTable('documents'));
    }

    // --- The tracker -------------------------------------------------------------------

    #[Test]
    public function the_library_lists_one_businesss_policies(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();

        $this->policies->draft($mine, 'Mine A');
        $this->policies->draft($mine, 'Mine B');
        $this->policies->draft($theirs, 'Theirs');

        $library = $this->policies->libraryFor($mine);

        $this->assertCount(2, $library);
        $this->assertSame(['Mine A', 'Mine B'], $library->pluck('title')->sort()->values()->all());
    }

    #[Test]
    public function a_policy_belongs_to_the_business_not_to_a_programme_run(): void
    {
        $this->assertTrue(Schema::hasColumn('hr_policies', 'customer_id'));
        $this->assertFalse(Schema::hasColumn('hr_policies', 'enrollment_id'));
    }

    #[Test]
    public function the_customer_on_a_policy_is_never_reassigned(): void
    {
        $policy = HrPolicy::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never reassigned');

        $policy->forceFill(['customer_id' => Customer::factory()->create()->getKey()])->save();
    }

    #[Test]
    public function a_superseded_policy_keeps_the_sign_offs_it_already_had(): void
    {
        $customer = Customer::factory()->create();
        $position = Position::factory()->create(['customer_id' => $customer->getKey()]);
        $v1 = $this->policies->draft($customer, 'Leave Policy', 'Twelve days a year.', 'v1');
        $this->policies->publish($v1, $this->admin());

        app(HrPolicyAcknowledgementService::class)
            ->record($v1->fresh(), $position, 'Priya Shah', $this->admin());

        $this->policies->supersede($v1->fresh(), 'Leave Policy', 'Eighteen days a year.', 'v2');

        $acknowledgement = $v1->fresh()->acknowledgements()->first();

        // The sign-off still points at the words that were actually read.
        $this->assertNotNull($acknowledgement);
        $this->assertSame($v1->getKey(), $acknowledgement->hr_policy_id);
        $this->assertSame('Twelve days a year.', $acknowledgement->hrPolicy->body);
    }
}
