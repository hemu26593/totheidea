<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Builders\AssignmentStatusReport;
use App\Domain\Reporting\Builders\AttendanceRegisterReport;
use App\Domain\Reporting\Builders\BatchSummaryReport;
use App\Domain\Reporting\Builders\ParticipantProgressReport;
use App\Domain\Reporting\ReportArtifactService;
use App\Domain\Reporting\ReportScope;
use App\Exceptions\CustomerIsolationException;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\ReportArtifact;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reports are the most dangerous thing in the system to get wrong.
 *
 * A mis-scoped operational query leaks a row. A mis-scoped report leaks a
 * whole business's position, in a document that is then handed to somebody.
 * So this file attacks the boundary from every direction a request can: a
 * substituted enrolment id, a substituted batch id, a substituted artifact,
 * and a role that may read but may not export.
 */
class ReportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private ReportArtifactService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        Storage::fake('local');
        $this->reports = app(ReportArtifactService::class);
    }

    /**
     * Two entirely separate programmes, each with its own participants.
     *
     * @return array{0: ReportScenario, 1: ReportScenario}
     */
    private function twoProgrammes(): array
    {
        $admin = $this->admin();

        return [
            (new ReportScenario($admin, 2))->full(),
            (new ReportScenario($admin, 2))->full(),
        ];
    }

    // --- A participant report never reaches another business ---------------------

    #[Test]
    public function a_participant_report_contains_only_that_participants_data(): void
    {
        [$a] = $this->twoProgrammes();

        $data = app(ParticipantProgressReport::class)->build($a->enrollments[0]);
        $payload = json_encode($data->toArray()) ?: '';

        $this->assertStringContainsString($a->names[0], $payload);
        // The other participant in the SAME batch is a different business and
        // must not appear in an individual's report either.
        $this->assertStringNotContainsString($a->names[1], $payload);
    }

    #[Test]
    public function substituting_another_businesss_enrolment_id_produces_that_businesss_report_not_a_merged_one(): void
    {
        [$a, $b] = $this->twoProgrammes();

        // The scope is resolved from the subject that was actually passed, so
        // a substituted id yields a correctly-scoped report about the other
        // enrolment - never a document blending the two.
        $data = app(ParticipantProgressReport::class)->build($b->enrollments[0]);

        $this->assertSame((int) $b->enrollments[0]->customer_id, $data->customerId);
        $this->assertNotSame((int) $a->enrollments[0]->customer_id, $data->customerId);
    }

    #[Test]
    public function a_report_subject_cannot_be_paired_with_another_businesss_customer(): void
    {
        [$a, $b] = $this->twoProgrammes();

        // A request carrying both a customer and a subject: the two are
        // compared, not trusted as a pair.
        $this->expectException(CustomerIsolationException::class);

        app(ReportScope::class)->assertSubjectBelongsTo(
            $b->enrollments[0],
            (int) $a->enrollments[0]->customer_id,
        );
    }

    // --- Batch reports never cross a batch ----------------------------------------

    #[Test]
    public function a_batch_report_reads_only_its_own_batch(): void
    {
        [$a, $b] = $this->twoProgrammes();

        $data = app(BatchSummaryReport::class)->build($a->batch);
        $batchRows = collect($data->sections[0]->rows)->keyBy(0)->map(fn (array $r): string => $r[1]);

        $this->assertSame('2', $batchRows['Participants']);
        $this->assertSame($a->batch->name, $batchRows['Name']);
        $this->assertNotSame($b->batch->name, $batchRows['Name']);
    }

    #[Test]
    public function the_register_never_lists_a_participant_from_another_batch(): void
    {
        [$a, $b] = $this->twoProgrammes();

        $data = app(AttendanceRegisterReport::class)->build($a->batch);
        $names = collect($data->sections[0]->rows)->map(fn (array $r): string => $r[0]);

        $this->assertCount(2, $names);

        foreach ($b->enrollments as $theirs) {
            $this->assertNotContains(
                (string) $theirs->customer?->name,
                $names->all(),
                'A register must never reach another batch.'
            );
        }
    }

    #[Test]
    public function the_register_never_counts_a_mark_from_another_batch(): void
    {
        [$a, $b] = $this->twoProgrammes();

        // Every mark in the system, across both programmes.
        $this->assertSame(4, SessionAttendance::query()->count());

        $data = app(AttendanceRegisterReport::class)->build($a->batch);
        $counts = collect($data->sections[1]->rows);

        // Only this batch's two marks are counted.
        $this->assertSame(2, $counts->sum(fn (array $r): int => (int) $r[5]));
    }

    #[Test]
    public function the_assignment_report_never_lists_another_batchs_assignment(): void
    {
        [$a, $b] = $this->twoProgrammes();
        $b->assignment->forceFill(['title' => 'Their private assignment'])->save();

        $data = app(AssignmentStatusReport::class)->build($a->batch);
        $payload = json_encode($data->toArray()) ?: '';

        $this->assertStringNotContainsString('Their private assignment', $payload);
    }

    #[Test]
    public function an_assignment_from_another_batch_is_unreachable_even_by_id(): void
    {
        [$a, $b] = $this->twoProgrammes();

        // The builder scopes through the batch's own session instances, so
        // there is no id an attacker could supply that would pull B's
        // assignment into A's report.
        $data = app(AssignmentStatusReport::class)->build($a->batch);
        $titles = collect($data->sections[0]->rows)->map(fn (array $r): string => $r[0]);

        $this->assertCount(1, $titles);
        $this->assertSame($a->assignment->title, $titles->first());
        $this->assertNotSame($b->assignment->getKey(), $a->assignment->getKey());
    }

    // --- Subject-type substitution -------------------------------------------------

    #[Test]
    public function a_report_key_cannot_be_paired_with_the_wrong_kind_of_subject(): void
    {
        [$a] = $this->twoProgrammes();

        // Pointing a participant report at a batch would silently change which
        // rows the builder reads.
        $this->expectException(InvalidArgumentException::class);

        $this->reports->generate('participant_progress', $a->batch, $this->admin());
    }

    #[Test]
    public function an_unknown_report_key_is_refused(): void
    {
        [$a] = $this->twoProgrammes();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown report');

        $this->reports->generate('everything_about_everyone', $a->batch, $this->admin());
    }

    #[Test]
    public function the_diagnostic_key_has_no_builder_and_fails_loudly(): void
    {
        [$a] = $this->twoProgrammes();

        // It is part of the artifact vocabulary but not of this phase.
        // Producing an empty document would be worse than refusing.
        $this->assertContains('diagnostic', ReportArtifact::REPORT_KEYS);

        $this->expectException(InvalidArgumentException::class);
        $this->reports->generate('diagnostic', $a->enrollments[0], $this->admin());
    }

    #[Test]
    public function an_unsupported_subject_type_fails_closed(): void
    {
        $this->expectException(CustomerIsolationException::class);

        app(ReportScope::class)->customerIdFor(Customer::factory()->create());
    }

    // --- Authorization ---------------------------------------------------------------

    #[Test]
    public function staff_may_read_a_report_but_not_export_one(): void
    {
        [$a] = $this->twoProgrammes();
        $staff = $this->staff();

        // Export writes a file that leaves the system. Phase 0 drew that line.
        $this->assertTrue($staff->can('reports.view'));
        $this->assertFalse($staff->can('reports.export'));

        $data = $this->reports->generate('batch_summary', $a->batch, $staff);
        $this->assertSame('batch_summary', $data->reportKey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reports.export is required');

        $this->reports->export('batch_summary', $a->batch, $staff);
    }

    #[Test]
    public function a_refused_export_writes_no_file_and_no_record(): void
    {
        [$a] = $this->twoProgrammes();

        try {
            $this->reports->export('batch_summary', $a->batch, $this->staff());
            $this->fail('Expected the export to be refused.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, ReportArtifact::query()->count());
    }

    #[Test]
    public function admin_may_export(): void
    {
        [$a] = $this->twoProgrammes();
        $admin = $this->admin();

        $this->assertTrue($admin->can('reports.export'));
        $this->assertTrue($admin->can('export', ReportArtifact::class));

        $artifact = $this->reports->export('batch_summary', $a->batch, $admin);
        $this->assertNotNull($artifact->fresh());
    }

    #[Test]
    public function a_user_with_no_report_permission_can_do_nothing(): void
    {
        [$a] = $this->twoProgrammes();
        $nobody = User::factory()->create();

        $this->assertFalse($nobody->can('reports.view'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reports.view is required');

        $this->reports->generate('batch_summary', $a->batch, $nobody);
    }

    #[Test]
    public function downloading_an_artifact_requires_permission(): void
    {
        [$a] = $this->twoProgrammes();
        $artifact = $this->reports->export('batch_summary', $a->batch, $this->admin());
        $nobody = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reports.view is required');

        $this->reports->contents($artifact, $nobody);
    }

    #[Test]
    public function nobody_deletes_or_rewrites_an_artifact(): void
    {
        $artifact = ReportArtifact::factory()->create();

        foreach (['superAdmin', 'admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('delete', $artifact));
        }

        foreach (['admin', 'staff'] as $role) {
            $this->assertFalse($this->{$role}()->can('update', $artifact));
        }
    }

    #[Test]
    public function the_report_policy_names_no_role(): void
    {
        $source = (string) file_get_contents(base_path('app/Policies/ReportArtifactPolicy.php'));

        foreach (['hasRole', 'super-admin', 'super_admin', "'admin'", "'staff'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function the_three_roles_are_unchanged(): void
    {
        $roles = Role::query()->pluck('name')->sort()->values()->all();

        $this->assertSame(['admin', 'staff', 'super-admin'], $roles);
    }

    // --- Artifact id substitution -------------------------------------------------------

    #[Test]
    public function an_artifact_names_the_subject_it_was_generated_for(): void
    {
        [$a, $b] = $this->twoProgrammes();
        $admin = $this->admin();

        $aArtifact = $this->reports->export('batch_summary', $a->batch, $admin);
        $bArtifact = $this->reports->export('batch_summary', $b->batch, $admin);

        // Substituting one artifact id for another retrieves the other
        // batch's file - correctly scoped to ITS batch, never a merged one.
        $this->assertSame($a->batch->getKey(), (int) $aArtifact->subject_id);
        $this->assertSame($b->batch->getKey(), (int) $bArtifact->subject_id);
        $this->assertNotSame($aArtifact->checksum_sha256, $bArtifact->checksum_sha256);

        $aBytes = $this->reports->contents($aArtifact, $admin);
        $this->assertStringContainsString($a->batch->code, $aBytes);
        $this->assertStringNotContainsString($b->batch->code, $aBytes);
    }

    #[Test]
    public function the_history_for_one_subject_never_lists_another(): void
    {
        [$a, $b] = $this->twoProgrammes();
        $admin = $this->admin();

        $this->reports->export('batch_summary', $a->batch, $admin);
        $this->reports->export('batch_summary', $b->batch, $admin);

        $history = $this->reports->historyFor($a->batch);

        $this->assertCount(1, $history);
        $this->assertSame($a->batch->getKey(), (int) $history->first()->subject_id);
    }

    // --- Two businesses, two different reports -------------------------------------------

    #[Test]
    public function customer_a_report_is_not_customer_b_report(): void
    {
        [$a, $b] = $this->twoProgrammes();

        $aData = app(ParticipantProgressReport::class)->build($a->enrollments[0]);
        $bData = app(ParticipantProgressReport::class)->build($b->enrollments[0]);

        $this->assertNotSame($aData->customerId, $bData->customerId);
        $this->assertNotSame(
            json_encode($aData->toArray()['sections']),
            json_encode($bData->toArray()['sections']),
        );
    }

    #[Test]
    public function no_report_aggregates_across_businesses_that_do_not_share_a_batch(): void
    {
        [$a, $b] = $this->twoProgrammes();

        // Every batch-level builder, checked against the other programme's
        // participants.
        foreach ([BatchSummaryReport::class, AttendanceRegisterReport::class, AssignmentStatusReport::class] as $builder) {
            $payload = json_encode(app($builder)->build($a->batch)->toArray()) ?: '';

            foreach ($b->enrollments as $theirs) {
                $this->assertStringNotContainsString(
                    (string) $theirs->customer?->name,
                    $payload,
                    class_basename($builder).' leaked a participant from another programme.'
                );
            }
        }
    }

    #[Test]
    public function an_enrolment_moved_into_view_by_id_is_still_scoped_by_its_own_batch(): void
    {
        [$a, $b] = $this->twoProgrammes();

        // A participant report about B's enrolment reads B's batch sessions -
        // it cannot be tricked into reading A's by any id the caller holds.
        $data = app(ParticipantProgressReport::class)->build($b->enrollments[0]);
        $sessions = collect($data->sections)->firstWhere('heading', 'Sessions');

        $bSessionIds = SessionInstance::query()->where('batch_id', $b->batch->getKey())->count();
        $this->assertCount($bSessionIds, $sessions->rows);
    }
}
