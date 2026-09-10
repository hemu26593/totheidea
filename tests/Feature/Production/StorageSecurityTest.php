<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Domain\Attachments\DocumentService;
use App\Domain\Reporting\ReportArtifactService;
use App\Livewire\Customers\Documents as DocumentsScreen;
use App\Livewire\Customers\Reports as ReportsScreen;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\ReportArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What must stay true about files once this runs on a real web server.
 *
 * A report aggregates a whole business's position and an uploaded document is
 * whatever the business sent. Neither may end up behind a URL that needs no
 * authorization - which, on a Laravel deployment, means neither may live on the
 * disk that `php artisan storage:link` publishes.
 */
class StorageSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reports_are_written_to_a_disk_that_the_storage_symlink_does_not_publish(): void
    {
        $disk = (string) config('reports.disk');

        $this->assertSame('local', $disk);

        $root = (string) config("filesystems.disks.{$disk}.root");
        $published = (string) config('filesystems.disks.public.root');

        $this->assertStringNotContainsString(
            $published,
            $root,
            "Report files must not live under the disk that storage:link exposes. `storage:link` maps\n"
            .'public/storage to that root, so anything there is downloadable by URL with no authorization.',
        );

        $this->assertNull(
            config("filesystems.disks.{$disk}.url"),
            'A disk with a public URL is a disk whose files can be fetched without asking a policy.',
        );

        // And the published disk carries nothing but its own placeholder.
        $this->assertSame(
            [],
            array_values(array_filter(
                Storage::disk('public')->allFiles(),
                fn (string $path): bool => ! str_ends_with($path, '.gitignore'),
            )),
        );
    }

    #[Test]
    public function an_uploaded_documents_path_is_generated_not_taken_from_the_client(): void
    {
        Storage::fake('local');
        $this->seedAuthorization();

        $customer = Customer::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(DocumentsScreen::class, ['customer' => $customer])
            ->call('startUpload')
            ->set('file', UploadedFile::fake()->create('../../../etc/passwd.pdf', 8, 'application/pdf'))
            ->call('upload')
            ->assertHasNoErrors();

        $document = Document::query()->sole();

        // Laravel hashes the stored name, so the client's filename never
        // becomes any part of the path.
        $this->assertStringStartsWith('documents/'.$customer->getKey().'/', $document->path);
        $this->assertStringNotContainsString('..', $document->path);
        $this->assertStringNotContainsString('passwd', $document->path);
        $this->assertStringNotContainsString('/etc/', $document->path);

        // The original name is kept as data - for the download header - and is
        // never used to resolve a location.
        $this->assertNotSame($document->original_name, basename($document->path));

        Storage::disk('local')->assertExists($document->path);
    }

    /**
     * FINDING (UAT-R1, reported - deliberately not changed here).
     *
     * Producing a report file is guarded by `reports.export`, which
     * config/authorization.php withholds from Staff and calls "a
     * data-exfiltration boundary". Reading an ALREADY-PRODUCED file back is
     * guarded by `reports.view`, which Staff hold - so a Staff user cannot
     * generate an artifact but can download any artifact an Admin generated.
     *
     * ReportArtifactPolicy says as much in its own docblock, so this is a
     * stated position rather than an oversight; whether the boundary is meant
     * to sit on creation only or on retrieval too is a permission-model
     * question for the client, and this phase does not change the permission
     * model. The behaviour is pinned here so the decision is visible.
     */
    #[Test]
    public function downloading_an_existing_report_is_guarded_by_view_not_by_export(): void
    {
        $this->seedAuthorization();

        $program = Program::factory()->create(['session_count' => 6]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $customer = Customer::factory()->create();
        Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        $admin = $this->admin();
        $staff = $this->staff();

        $artifact = app(ReportArtifactService::class)
            ->export('attendance_register', $batch, $admin, ReportArtifact::FORMAT_CSV);

        // Staff cannot produce one.
        $this->assertFalse($staff->can('reports.export'));

        // But they may read one back, because download maps to reports.view.
        $this->assertTrue($staff->can('reports.view'));
        $this->assertTrue($staff->can('download', $artifact));
        $this->assertTrue($admin->can('download', $artifact));

        // A user with neither cannot.
        $roleless = User::factory()->create();
        $this->assertFalse($roleless->can('download', $artifact));
    }

    #[Test]
    public function another_businesss_report_artifact_is_not_reachable_from_this_workspace(): void
    {
        $this->seedAuthorization();

        $program = Program::factory()->create(['session_count' => 6]);
        $alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        $betaBatch = Batch::factory()->create(['program_id' => $program->getKey()]);
        Enrollment::factory()->create(['customer_id' => $beta->getKey(), 'batch_id' => $betaBatch->getKey()]);

        $admin = $this->admin();
        $betaArtifact = app(ReportArtifactService::class)
            ->export('attendance_register', $betaBatch, $admin, ReportArtifact::FORMAT_CSV);

        Livewire::actingAs($admin)
            ->test(ReportsScreen::class, ['customer' => $alpha])
            ->call('download', $betaArtifact->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function a_stored_report_is_refused_if_its_bytes_no_longer_match_what_was_issued(): void
    {
        $this->seedAuthorization();

        $program = Program::factory()->create(['session_count' => 6]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $customer = Customer::factory()->create();
        Enrollment::factory()->create(['customer_id' => $customer->getKey(), 'batch_id' => $batch->getKey()]);

        $admin = $this->admin();
        $artifact = app(ReportArtifactService::class)
            ->export('attendance_register', $batch, $admin, ReportArtifact::FORMAT_CSV);

        Storage::disk($artifact->disk)->put($artifact->path, 'tampered');

        $this->expectException(\RuntimeException::class);
        app(ReportArtifactService::class)->contents($artifact->fresh(), $admin);
    }

    #[Test]
    public function a_document_is_attached_to_its_own_customer_and_nowhere_else(): void
    {
        Storage::fake('local');
        $this->seedAuthorization();

        $alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        Livewire::actingAs($this->admin())
            ->test(DocumentsScreen::class, ['customer' => $alpha])
            ->call('startUpload')
            ->set('file', UploadedFile::fake()->create('policy.pdf', 8, 'application/pdf'))
            ->call('upload')
            ->assertHasNoErrors();

        $document = Document::query()->sole();

        $this->assertSame(Customer::class, $document->documentable_type);
        $this->assertSame((int) $alpha->getKey(), (int) $document->documentable_id);

        // And Beta's workspace cannot download it.
        Livewire::actingAs($this->admin())
            ->test(DocumentsScreen::class, ['customer' => $beta])
            ->call('download', $document->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function the_document_service_is_the_only_thing_that_writes_a_document_row(): void
    {
        // Guards architecture rule 7: the upload screen resolves the file and
        // delegates; it never builds the row itself.
        $screen = (string) file_get_contents(app_path('Livewire/Customers/Documents.php'));

        $this->assertStringNotContainsString('new Document', $screen);
        $this->assertStringNotContainsString('Document::create', $screen);
        $this->assertStringContainsString('DocumentService', $screen);
        $this->assertTrue(method_exists(DocumentService::class, 'attachByStaff'));
    }
}
