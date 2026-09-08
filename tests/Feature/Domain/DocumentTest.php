<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Access\AccessGrantService;
use App\Domain\Attachments\DocumentService;
use App\Enums\ActorSource;
use App\Enums\GrantAbility;
use App\Exceptions\CustomerIsolationException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Documents attach to a subject rather than to a customer, so their isolation
 * cannot be a foreign key. These tests exercise the code that stands in for
 * one.
 */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $documents;

    private AccessGrantService $grants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->documents = app(DocumentService::class);
        $this->grants = app(AccessGrantService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function file(): array
    {
        return [
            'path' => 'documents/abc.pdf',
            'original_name' => 'workbook.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ];
    }

    // --- Creation and ownership -------------------------------------------

    #[Test]
    public function staff_can_attach_a_document_to_a_customer(): void
    {
        $actor = $this->admin();
        $customer = Customer::factory()->create();

        $document = $this->documents->attachByStaff($customer, $this->file(), $actor);

        $this->assertSame($customer->getMorphClass(), $document->documentable_type);
        $this->assertSame($customer->getKey(), (int) $document->documentable_id);
        $this->assertSame(ActorSource::InternalUser, $document->source);
        $this->assertSame($actor->getKey(), $document->created_by);
        $this->assertNull($document->access_grant_id);
    }

    #[Test]
    public function a_document_defaults_to_internal(): void
    {
        // Fails closed: a document whose visibility was never decided must
        // not be visible.
        $document = $this->documents->attachByStaff(
            Customer::factory()->create(), $this->file(), $this->admin(),
        );

        $this->assertTrue($document->is_internal);
    }

    #[Test]
    public function a_document_can_attach_to_an_enrolment(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $document = $this->documents->attachByStaff($enrollment, $this->file(), $this->admin());

        $this->assertSame($enrollment->getMorphClass(), $document->documentable_type);
        $this->assertTrue($document->documentable->is($enrollment));
    }

    #[Test]
    public function a_subject_whose_owner_cannot_be_resolved_is_refused(): void
    {
        // Fails CLOSED - a later phase adding an attachable type must make it
        // resolvable deliberately.
        $this->expectException(CustomerIsolationException::class);

        $this->documents->attachByStaff($this->admin(), $this->file(), $this->admin());
    }

    // --- Customer isolation ------------------------------------------------

    #[Test]
    public function customer_a_cannot_read_customer_b_documents(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $this->documents->attachByStaff($b, $this->file(), $this->admin());

        $this->expectException(CustomerIsolationException::class);

        // Subject belongs to B; caller claims A.
        $this->documents->forSubject($b, $a);
    }

    #[Test]
    public function reading_a_subject_returns_only_that_subjects_documents(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $actor = $this->admin();

        $this->documents->attachByStaff($a, $this->file(), $actor);
        $this->documents->attachByStaff($a, $this->file(), $actor);
        $this->documents->attachByStaff($b, $this->file(), $actor);

        $this->assertCount(2, $this->documents->forSubject($a, $a));
        $this->assertCount(1, $this->documents->forSubject($b, $b));
    }

    #[Test]
    public function a_manipulated_customer_id_does_not_widen_a_read(): void
    {
        // The caller supplies both; the service verifies the subject rather
        // than trusting the pairing.
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $bEnrollment = Enrollment::factory()->create(['customer_id' => $b->getKey()]);

        $this->expectException(CustomerIsolationException::class);

        $this->documents->forSubject($bEnrollment, $a);
    }

    #[Test]
    public function a_grant_for_one_customer_cannot_attach_to_another(): void
    {
        $a = Customer::factory()->create();
        $aEnrollment = Enrollment::factory()->create(['customer_id' => $a->getKey()]);
        $b = Customer::factory()->create();

        $grant = $this->grants->issue(
            $a, $aEnrollment, $aEnrollment,
            GrantAbility::CompleteForm, now()->addDay(), $this->admin(),
        )->grant;

        $this->expectException(CustomerIsolationException::class);

        $this->documents->attachByGrant($b, $this->file(), $grant);
    }

    #[Test]
    public function a_grant_cannot_read_another_customers_documents(): void
    {
        $a = Customer::factory()->create();
        $aEnrollment = Enrollment::factory()->create(['customer_id' => $a->getKey()]);
        $b = Customer::factory()->create();

        $grant = $this->grants->issue(
            $a, $aEnrollment, $aEnrollment,
            GrantAbility::ViewReport, now()->addDay(), $this->admin(),
        )->grant;

        $this->expectException(CustomerIsolationException::class);

        $this->documents->forSubjectExternally($b, $grant);
    }

    // --- External visibility ----------------------------------------------

    #[Test]
    public function an_external_read_never_returns_an_internal_document(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $actor = $this->admin();

        $this->documents->attachByStaff($enrollment, $this->file(), $actor, internal: true);
        $this->documents->attachByStaff($enrollment, $this->file(), $actor, internal: false);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::ViewReport, now()->addDay(), $actor,
        )->grant;

        $visible = $this->documents->forSubjectExternally($enrollment, $grant);

        $this->assertCount(1, $visible);
        $this->assertFalse($visible->first()->is_internal);
    }

    #[Test]
    public function an_external_read_never_returns_an_archived_document(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $actor = $this->admin();

        $document = $this->documents->attachByStaff($enrollment, $this->file(), $actor, internal: false);
        $this->documents->archive($document, $actor);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::ViewReport, now()->addDay(), $actor,
        )->grant;

        $this->assertCount(0, $this->documents->forSubjectExternally($enrollment, $grant));
    }

    // --- Actor triple and audit -------------------------------------------

    #[Test]
    public function a_document_attached_through_a_grant_records_the_grant_not_a_user(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::SubmitAssignment, now()->addDay(), $this->admin(),
        )->grant;

        $document = $this->documents->attachByGrant($enrollment, $this->file(), $grant);

        $this->assertSame(ActorSource::ExternalGrant, $document->source);
        $this->assertSame($grant->getKey(), $document->access_grant_id);
        $this->assertNull($document->created_by);
    }

    #[Test]
    public function an_incoherent_actor_triple_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        Document::factory()->create([
            'source' => ActorSource::InternalUser,
            'created_by' => null,
        ]);
    }

    #[Test]
    public function an_external_upload_is_audited_with_the_external_source(): void
    {
        $customer = Customer::factory()->create();
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $grant = $this->grants->issue(
            $customer, $enrollment, $enrollment,
            GrantAbility::SubmitAssignment, now()->addDay(), $this->admin(),
        )->grant;

        $document = $this->documents->attachByGrant($enrollment, $this->file(), $grant);

        $entry = AuditLog::query()
            ->where('auditable_type', $document->getMorphClass())
            ->where('auditable_id', $document->getKey())
            ->firstOrFail();

        $this->assertSame(ActorSource::ExternalGrant, $entry->source);
        $this->assertSame($grant->getKey(), $entry->access_grant_id);
        $this->assertNull($entry->actor_id);
    }

    // --- Archive-only -----------------------------------------------------

    #[Test]
    public function archiving_retains_the_row_and_the_stored_file_reference(): void
    {
        $actor = $this->admin();
        $document = $this->documents->attachByStaff(Customer::factory()->create(), $this->file(), $actor);

        $archived = $this->documents->archive($document, $actor);

        $this->assertDatabaseHas('documents', ['id' => $document->getKey()]);
        $this->assertNotNull($archived->archived_at);
        // The path survives: a delivered document must stay retrievable.
        $this->assertSame('documents/abc.pdf', $archived->path);
    }

    #[Test]
    public function no_role_can_delete_a_document(): void
    {
        $document = Document::factory()->create();

        foreach (['superAdmin', 'admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('delete', $document));
        }
    }

    // --- Authorization ----------------------------------------------------

    #[Test]
    public function attaching_requires_the_documents_permission(): void
    {
        $document = Document::factory()->create();

        foreach (['admin', 'staff'] as $role) {
            $actor = $this->{$role}();
            $this->assertTrue($actor->can('create', Document::class));
            $this->assertTrue($actor->can('archive', $document));
        }

        $nobody = User::factory()->create();
        $this->assertFalse($nobody->can('create', Document::class));
        $this->assertFalse($nobody->can('archive', $document));
    }
}
