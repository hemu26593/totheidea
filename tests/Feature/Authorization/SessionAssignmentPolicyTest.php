<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\AssignmentInstance;
use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentTemplate;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\SessionTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 4 policies against the permission matrix.
 *
 * Two things are being checked, and they are not the same thing:
 *
 *   1. That each ability asks about a CAPABILITY, never a role name.
 *   2. That the refusals which must be absolute do not depend on a policy at
 *      all - Gate::before grants a Super Admin every ability outside
 *      authorization.guarded_abilities, so a policy returning false is a
 *      statement of intent, not a guarantee. The guarantee is on the model.
 */
class SessionAssignmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    // --- Sessions ----------------------------------------------------------

    #[Test]
    public function admin_may_author_and_schedule_sessions(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->can('create', SessionTemplate::class));
        $this->assertTrue($admin->can('archive', SessionTemplate::factory()->create()));
        $this->assertTrue($admin->can('create', SessionInstance::class));
        $this->assertTrue($admin->can('complete', SessionInstance::factory()->create()));
    }

    #[Test]
    public function staff_may_run_sessions_but_not_retire_the_curriculum(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->can('view', SessionTemplate::factory()->create()));
        $this->assertTrue($staff->can('create', SessionInstance::class));
        $this->assertTrue($staff->can('complete', SessionInstance::factory()->create()));

        // Staff holds no sessions.archive.
        $this->assertFalse($staff->can('archive', SessionTemplate::factory()->create()));
    }

    #[Test]
    public function attendance_marking_is_its_own_capability(): void
    {
        $mark = SessionAttendance::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $actor = $this->{$role}();
            $this->assertTrue($actor->can('view', $mark), "[{$role}] should read the register.");
            $this->assertTrue($actor->can('amend', $mark), "[{$role}] should amend a mark.");
        }
    }

    // --- Assignments -------------------------------------------------------

    #[Test]
    public function staff_may_release_and_review_but_not_archive_assignments(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->can('release', AssignmentInstance::factory()->create()));
        $this->assertTrue($staff->can('review', AssignmentSubmission::factory()->create()));
        $this->assertFalse($staff->can('archive', AssignmentTemplate::factory()->create()));
    }

    #[Test]
    public function admin_may_archive_an_assignment_template(): void
    {
        $this->assertTrue($this->admin()->can('archive', AssignmentTemplate::factory()->create()));
    }

    // --- Nothing in Phase 4 is deletable -----------------------------------

    #[Test]
    public function no_role_can_delete_any_phase_four_record(): void
    {
        $records = [
            SessionTemplate::factory()->create(),
            SessionInstance::factory()->create(),
            SessionAttendance::factory()->create(),
            AssignmentTemplate::factory()->create(),
            AssignmentInstance::factory()->create(),
            AssignmentSubmission::factory()->create(),
            AssignmentReview::factory()->create(),
        ];

        foreach ($records as $record) {
            foreach (['superAdmin', 'admin', 'staff'] as $role) {
                $this->assertFalse(
                    $this->{$role}()->can('delete', $record),
                    sprintf('[%s] must not delete a %s.', $role, $record::class),
                );
            }
        }
    }

    #[Test]
    public function delete_being_a_guarded_ability_is_what_makes_those_refusals_reach_a_super_admin(): void
    {
        $this->assertContains('delete', config('authorization.guarded_abilities'));
    }

    #[Test]
    public function the_review_update_refusal_is_backed_by_the_model_not_only_the_policy(): void
    {
        $review = AssignmentReview::factory()->create();

        // 'update' is NOT guarded, so Gate::before grants it to a Super Admin
        // and the policy is never consulted. That is precisely why the
        // immutability guard lives on the model.
        $this->assertTrue($this->superAdmin()->can('update', $review));

        $this->expectException(\RuntimeException::class);
        $review->forceFill(['remark' => 'Rewritten.'])->save();
    }

    #[Test]
    public function no_ability_in_these_policies_names_a_role(): void
    {
        // Capabilities, never role names - config/authorization.php is the
        // source of truth for who holds what.
        foreach ([
            'SessionTemplatePolicy', 'SessionInstancePolicy', 'SessionAttendancePolicy',
            'AssignmentTemplatePolicy', 'AssignmentInstancePolicy',
            'AssignmentSubmissionPolicy', 'AssignmentReviewPolicy',
        ] as $policy) {
            $source = file_get_contents(base_path("app/Policies/{$policy}.php"));

            foreach (['hasRole', 'super-admin', 'super_admin', "'admin'", "'staff'"] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$policy} must ask about capabilities, not roles."
                );
            }
        }
    }
}
