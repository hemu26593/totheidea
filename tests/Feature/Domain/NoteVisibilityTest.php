<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Access\AccessGrantService;
use App\Domain\Attachments\NoteService;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AccessGrant;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The private-notes boundary.
 *
 * SOW module 14 requires notes participants cannot see, and SOW section 6
 * requires that isolation be "built in, not a setting". These tests exist
 * because a Blade "can" directive hides a button - it does not stop a row
 * being loaded and returned.
 */
class NoteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private NoteService $notes;

    private AccessGrantService $grants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->notes = app(NoteService::class);
        $this->grants = app(AccessGrantService::class);
    }

    private function grantFor(Customer $customer, Enrollment $enrollment): AccessGrant
    {
        return $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::ViewReport, now()->addDay(), $this->admin(),
        )->grant;
    }

    // --- Defaults ---------------------------------------------------------

    #[Test]
    public function a_note_defaults_to_internal(): void
    {
        // The direction matters: forgetting the flag must over-restrict, not
        // leak a consultant's private assessment to the participant.
        $note = $this->notes->create(Customer::factory()->create(), 'Struggling with cashflow', $this->admin());

        $this->assertTrue($note->is_internal);
    }

    #[Test]
    public function the_database_default_is_also_internal(): void
    {
        // Belt and braces: a row inserted outside the service is still private.
        $customer = Customer::factory()->create();
        $author = $this->admin();

        DB::table('notes')->insert([
            'notable_type' => $customer->getMorphClass(),
            'notable_id' => $customer->getKey(),
            'body' => 'Raw insert',
            'author_id' => $author->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(Note::query()->firstOrFail()->is_internal);
    }

    #[Test]
    public function a_customer_visible_note_must_be_asked_for_explicitly(): void
    {
        $note = $this->notes->create(
            Customer::factory()->create(), 'Shared summary', $this->admin(), internal: false,
        );

        $this->assertFalse($note->is_internal);
    }

    // --- The authorization boundary ---------------------------------------

    #[Test]
    public function reading_an_internal_note_requires_notes_view_internal(): void
    {
        $note = Note::factory()->create();

        $withPermission = $this->admin();
        $withoutPermission = User::factory()->create();

        $this->assertTrue($withPermission->can('notes.view_internal'));
        $this->assertTrue($withPermission->can('view', $note));

        $this->assertFalse($withoutPermission->can('notes.view_internal'));
        $this->assertFalse($withoutPermission->can('view', $note));
    }

    #[Test]
    public function a_customer_visible_note_needs_only_customers_view(): void
    {
        $note = Note::factory()->customerVisible()->create();

        $this->assertTrue($this->staff()->can('view', $note));
        $this->assertFalse(User::factory()->create()->can('view', $note));
    }

    #[Test]
    public function the_boundary_is_enforced_in_the_query_not_only_in_the_policy(): void
    {
        // A reader without the permission does not receive the rows at all,
        // rather than receiving them and being trusted not to render them.
        $customer = Customer::factory()->create();
        $author = $this->admin();

        $this->notes->create($customer, 'Private assessment', $author, internal: true);
        $this->notes->create($customer, 'Shared summary', $author, internal: false);

        $permitted = $this->notes->visibleToStaff($customer, $customer, $this->admin());
        $this->assertCount(2, $permitted);

        $restricted = $this->notes->visibleToStaff($customer, $customer, User::factory()->create());
        $this->assertCount(1, $restricted);
        $this->assertFalse($restricted->first()->is_internal);
    }

    #[Test]
    public function staff_hold_notes_view_internal_per_the_phase_zero_matrix(): void
    {
        $this->assertTrue($this->staff()->can('notes.view_internal'));
        $this->assertTrue($this->staff()->can('notes.manage'));
    }

    // --- The external boundary --------------------------------------------

    #[Test]
    public function an_external_read_never_returns_an_internal_note(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $author = $this->admin();

        $this->notes->create($enrollment, 'Private: chasing payment', $author, internal: true);
        $this->notes->create($enrollment, 'Shared: well done', $author, internal: false);

        $visible = $this->notes->visibleExternally($enrollment, $this->grantFor($customer, $enrollment));

        $this->assertCount(1, $visible);
        $this->assertFalse($visible->first()->is_internal);
        $this->assertStringNotContainsString('Private', $visible->first()->body);
    }

    #[Test]
    public function knowing_a_note_id_does_not_expose_it_externally(): void
    {
        // There is no external path that takes a note id. The only external
        // read is subject-scoped and structurally excludes internal notes -
        // no permission, argument or actor widens it.
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $internal = $this->notes->create($enrollment, 'Private', $this->admin(), internal: true);

        $visible = $this->notes->visibleExternally($enrollment, $this->grantFor($customer, $enrollment));

        $this->assertFalse($visible->contains(fn (Note $n): bool => $n->is($internal)));
    }

    #[Test]
    public function the_external_read_takes_no_actor_that_could_widen_it(): void
    {
        // Signature check: visibleExternally accepts a subject and a grant.
        // There is no User parameter, so no permission can be consulted.
        $parameters = collect((new ReflectionMethod(NoteService::class, 'visibleExternally'))->getParameters())
            ->map(fn (\ReflectionParameter $p): string => (string) $p->getType());

        $this->assertFalse($parameters->contains(User::class));
        $this->assertTrue($parameters->contains(AccessGrant::class));
    }

    #[Test]
    public function an_external_grant_cannot_author_a_note(): void
    {
        // Structural, not conditional: every write method requires a User, and
        // notes carry no actor triple at all.
        foreach (['create', 'updateBody', 'setVisibility', 'archive'] as $method) {
            $types = collect((new ReflectionMethod(NoteService::class, $method))->getParameters())
                ->map(fn (\ReflectionParameter $p): string => (string) $p->getType());

            $this->assertFalse(
                $types->contains(AccessGrant::class),
                "NoteService::{$method}() must not accept an AccessGrant."
            );
        }

        $this->assertContains(
            User::class,
            collect((new ReflectionMethod(NoteService::class, 'create'))->getParameters())
                ->map(fn (\ReflectionParameter $p): string => (string) $p->getType())->all()
        );
    }

    #[Test]
    public function an_external_read_cannot_reach_another_customers_notes(): void
    {
        $a = Customer::factory()->create();
        $aEnrollment = Enrollment::factory()->create(['customer_id' => $a->getKey()]);
        $b = Customer::factory()->create();

        $this->expectException(CustomerIsolationException::class);

        $this->notes->visibleExternally($b, $this->grantFor($a, $aEnrollment));
    }

    // --- Customer isolation ------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_notes(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $this->notes->create($b, 'B private', $this->admin());

        $this->expectException(CustomerIsolationException::class);

        $this->notes->visibleToStaff($b, $a, $this->admin());
    }

    #[Test]
    public function a_manipulated_subject_id_does_not_widen_a_read(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $bEnrollment = Enrollment::factory()->create(['customer_id' => $b->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        $this->notes->visibleToStaff($bEnrollment, $a, $this->admin());
    }

    // --- Management -------------------------------------------------------

    #[Test]
    public function changing_a_note_to_customer_visible_is_audited(): void
    {
        $actor = $this->admin();
        $note = $this->notes->create(Customer::factory()->create(), 'Private', $actor);

        $this->notes->setVisibility($note, false, $actor);

        $this->assertFalse($note->fresh()->is_internal);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $note->getMorphClass(),
            'auditable_id' => $note->getKey(),
        ]);
    }

    #[Test]
    public function disclosing_a_private_note_requires_both_permissions(): void
    {
        $note = Note::factory()->create();

        $this->assertTrue($this->admin()->can('setVisibility', $note));
        $this->assertFalse(User::factory()->create()->can('setVisibility', $note));
    }

    #[Test]
    public function is_internal_cannot_be_mass_assigned(): void
    {
        // Visibility is decided by the service, never by request input.
        $note = new Note(['body' => 'x', 'is_internal' => false]);

        $this->assertNotFalse($note->is_internal);
    }

    #[Test]
    public function a_note_is_archived_never_deleted(): void
    {
        $actor = $this->admin();
        $note = $this->notes->create(Customer::factory()->create(), 'Private', $actor);

        $this->notes->archive($note, $actor);

        $this->assertDatabaseHas('notes', ['id' => $note->getKey()]);
        $this->assertNotNull($note->fresh()->archived_at);

        foreach (['superAdmin', 'admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('delete', $note));
        }
    }

    #[Test]
    public function a_notes_author_is_always_an_internal_user(): void
    {
        $author = $this->admin();
        $note = $this->notes->create(Customer::factory()->create(), 'Private', $author);

        $this->assertTrue($note->author->is($author));
        $this->assertInstanceOf(User::class, $note->author);
    }
}
