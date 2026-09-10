<?php

declare(strict_types=1);

namespace Tests\Feature\Uat;

use App\Livewire\Admin\Curriculum;
use App\Livewire\Admin\FormTemplates;
use App\Livewire\Admin\Programs;
use App\Livewire\Admin\Roles;
use App\Livewire\Ai\Prompts;
use App\Livewire\Customers\ManageCustomer;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\AiPromptVersion;
use App\Models\AssignmentTemplate;
use App\Models\Batch;
use App\Models\FormTemplate;
use App\Models\Program;
use App\Models\SessionTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UAT section 3 and section 20: the actor matrix, executed rather than read.
 *
 * Every assertion here is the result of actually calling the ability or
 * mounting the screen. None of it infers authorization from a hidden button.
 *
 * The tests are written as characterisation, not as a wish: where the platform
 * grants more than the role notes imply, the test records what it does and the
 * finding is raised in the UAT report. Rewriting the permission matrix to match
 * an assumption would be inventing a rule the client has not made.
 */
class ActorMatrixUatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    /*
    |--------------------------------------------------------------------------
    | What each actor may open
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function user_administration_is_closed_to_staff_and_open_to_admin(): void
    {
        Livewire::actingAs($this->staff())->test(UsersIndex::class)->assertForbidden();
    }

    #[Test]
    public function admin_and_super_admin_reach_user_administration(): void
    {
        Livewire::actingAs($this->admin())->test(UsersIndex::class)->assertOk();
        Livewire::actingAs($this->superAdmin())->test(UsersIndex::class)->assertOk();
    }

    #[Test]
    public function roles_and_permissions_are_super_admin_only(): void
    {
        Livewire::actingAs($this->superAdmin())->test(Roles::class)->assertOk();

        foreach ([$this->admin(), $this->staff()] as $actor) {
            Livewire::actingAs($actor)->test(Roles::class)->assertForbidden();
        }
    }

    #[Test]
    public function bringing_a_new_business_onto_the_programme_is_an_admin_act(): void
    {
        Livewire::actingAs($this->staff())->test(ManageCustomer::class)->assertForbidden();
    }

    #[Test]
    public function staff_may_read_the_admin_library_screens_they_hold_view_rights_for(): void
    {
        $staff = $this->staff();

        // Each of these screens authorizes viewAny on its own model in mount().
        // Staff holds batches.view, forms.view, sessions.view and
        // ai.analysis.view, so each screen opens read-only for them.
        Livewire::actingAs($staff)->test(Programs::class)->assertOk();
        Livewire::actingAs($staff)->test(FormTemplates::class)->assertOk();
        Livewire::actingAs($staff)->test(Curriculum::class)->assertOk();
        Livewire::actingAs($staff)->test(Prompts::class)->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | What each actor may change - the part that matters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function staff_cannot_author_form_templates_batches_or_ai_prompts(): void
    {
        $staff = $this->staff();

        $this->assertFalse($staff->can('create', FormTemplate::class), 'Staff must not author form templates.');
        $this->assertFalse($staff->can('create', Batch::class), 'Staff must not open a batch.');
        $this->assertFalse($staff->can('create', AiPromptVersion::class), 'Staff must not author AI prompts.');

        // And the screens refuse the action, not merely the button.
        Livewire::actingAs($staff)->test(FormTemplates::class)->call('startTemplate')->assertForbidden();
        Livewire::actingAs($staff)->test(Programs::class)->call('startCreate')->assertForbidden();
        Livewire::actingAs($staff)->test(Prompts::class)->call('startDraft')->assertForbidden();
    }

    /**
     * FINDING (UAT-A1, reported - deliberately not "fixed" here).
     *
     * `sessions.create` and `assignments.create` each govern two different
     * objects: scheduling a session or releasing an assignment for one batch,
     * and authoring the programme's curriculum template that every batch
     * inherits. Staff holds both permissions for the first purpose, and
     * therefore holds them for the second as well.
     *
     * The result is that a Staff user can define and edit the BMP curriculum
     * with exactly the authority of an Admin, while being explicitly denied
     * form-template authoring, batch creation and AI prompt authoring - which
     * reads as an oversight in the permission vocabulary rather than a
     * decision. Separating them means adding permissions to
     * config/authorization.php and deciding them per role, which is a client
     * decision recorded in an ADR, not something UAT invents.
     *
     * This test pins the behaviour as it stands so the decision is visible and
     * any change to it is deliberate.
     */
    #[Test]
    public function staff_can_author_the_curriculum_because_two_permissions_are_overloaded(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->can('sessions.create'), 'Staff schedules sessions - this part is intended.');
        $this->assertTrue($staff->can('assignments.create'), 'Staff releases assignments - this part is intended.');

        // The same two permissions also unlock curriculum authoring.
        $this->assertTrue($staff->can('create', SessionTemplate::class));
        $this->assertTrue($staff->can('create', AssignmentTemplate::class));

        $program = Program::factory()->create(['session_count' => 6]);

        Livewire::actingAs($staff)
            ->test(Curriculum::class)
            ->set('programId', $program->getKey())
            ->call('startSession')
            ->set('sequence', 1)
            ->set('title', 'Foundation & Diagnostics')
            ->set('theme', 'Where the business is now')
            ->call('defineSession')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('session_templates', [
            'program_id' => $program->getKey(),
            'sequence' => 1,
            'title' => 'Foundation & Diagnostics',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Super Admin is not above the safeguards
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function super_admin_is_granted_everything_except_the_guarded_abilities(): void
    {
        $superAdmin = $this->superAdmin();

        // Gate::before grants the ordinary abilities outright.
        $this->assertTrue($superAdmin->can('create', FormTemplate::class));
        $this->assertTrue($superAdmin->can('create', Batch::class));

        // The guarded ones fall through to the policy, which refuses.
        $this->assertFalse($superAdmin->can('delete', $superAdmin));
        $this->assertFalse($superAdmin->can('deactivate', $superAdmin));
        $this->assertFalse($superAdmin->can('assignRole', $superAdmin));
    }
}
