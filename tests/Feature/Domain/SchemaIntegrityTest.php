<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The schema at the current phase: what must exist, and what must NOT exist yet.
 *
 * The negative assertions matter as much as the positive ones. A later-phase
 * table appearing early means the build order was skipped, and build order is
 * what keeps every phase independently shippable.
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every BMP table that should exist at the current phase.
     *
     * @var array<int, string>
     */
    private const BMP_TABLES = [
        // Phase 1 - core spine and access grants
        'programs',
        'batches',
        'customers',
        'customer_contacts',
        'enrollments',
        'access_grants',
        'terms_acceptances',
        // Phase 2 - shared attachments
        'documents',
        'notes',
        // Phase 3 - form engine, intake and scoring
        'skill_areas',
        'form_templates',
        'form_versions',
        'form_sections',
        'questions',
        'question_options',
        'form_submissions',
        'answers',
        'answer_options',
        'submission_scores',
        // Phase 4 - sessions, assignments and attendance
        'session_templates',
        'session_template_forms',
        'session_instances',
        'session_attendances',
        'assignment_templates',
        'assignment_instances',
        'assignment_submissions',
        'assignment_reviews',
        // Phase 5 - notification dispatch log (table 38 in the frozen order,
        // built here because nothing in the notification architecture depends
        // on the trackers).
        'notification_dispatches',
    ];

    /** @var array<int, string> */
    private const FOUNDATION_TABLES = [
        'users', 'password_reset_tokens', 'sessions',
        'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'audit_logs',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
    ];

    /** @var array<int, string> */
    private const LATER_PHASE_TABLES = [
        'day_plan_items', 'time_grid_entries', 'mmd_entries', 'mmd_targets',
        'fund_plans', 'fund_plan_lines', 'action_items',
        'positions', 'hr_policies', 'hr_policy_acknowledgements',
        'report_artifacts',
        'ai_prompt_versions', 'ai_generations', 'ai_approvals',
        // Deferred client decisions - these must not appear at all.
        'end_customers', 'end_customer_interactions',
    ];

    #[Test]
    public function every_expected_bmp_table_exists(): void
    {
        foreach (self::BMP_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}].");
        }
    }

    #[Test]
    public function the_foundation_tables_are_intact(): void
    {
        foreach (self::FOUNDATION_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Foundation table [{$table}] must survive.");
        }
    }

    #[Test]
    public function no_later_phase_table_exists_yet(): void
    {
        foreach (self::LATER_PHASE_TABLES as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "[{$table}] belongs to a later phase and must not exist yet."
            );
        }
    }

    #[Test]
    public function the_audit_table_was_extended_not_replaced(): void
    {
        // A1 adds two columns to the existing Step 2 table. The original
        // columns must all survive - there is one audit system, not two.
        foreach (['actor_id', 'actor_label', 'action', 'auditable_type', 'auditable_id',
            'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('audit_logs', $column),
                "audit_logs must retain [{$column}]."
            );
        }

        $this->assertTrue(Schema::hasColumn('audit_logs', 'source'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'access_grant_id'));
    }

    #[Test]
    public function customers_carry_no_credential_column(): void
    {
        // Customer != User is structural. A credential column here would be an
        // architecture change requiring an ADR, not a migration.
        foreach ([
            'password', 'remember_token', 'email_verified_at',
            'two_factor_secret', 'two_factor_recovery_codes',
            'user_id', 'is_active', 'last_login_at',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('customers', $column),
                "customers must not have [{$column}] - customers do not authenticate."
            );
        }
    }

    #[Test]
    public function customers_have_no_foreign_key_to_users_for_ownership(): void
    {
        // archived_by is the ONLY link, and it records which member of staff
        // archived the record - provenance, not ownership.
        $userColumns = collect(Schema::getColumnListing('customers'))
            ->filter(fn (string $c): bool => str_contains($c, 'user'))
            ->values()
            ->all();

        $this->assertSame([], $userColumns, 'customers must not reference users for ownership.');
    }

    #[Test]
    public function the_enrollment_uniqueness_constraint_is_a_database_constraint(): void
    {
        $indexes = collect(Schema::getIndexes('enrollments'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertTrue(
            $indexes->contains(['customer_id', 'batch_id']),
            'UNIQUE (customer_id, batch_id) must be enforced by the database, not only by code.'
        );
    }

    #[Test]
    public function the_bmp_migrations_roll_back_and_re_apply(): void
    {
        // A migration that cannot be rolled back is a migration that cannot be
        // fixed in place on staging. Twenty-nine steps: twenty-eight tables
        // plus A1.
        $this->artisan('migrate:rollback', ['--step' => 29])->assertSuccessful();

        foreach (self::BMP_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "[{$table}] should have been rolled back.");
        }

        // A1 reverses cleanly without disturbing the original audit columns.
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'source'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'access_grant_id'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'actor_id'));

        $this->artisan('migrate')->assertSuccessful();

        foreach (self::BMP_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "[{$table}] should have been re-applied.");
        }

        $this->assertTrue(Schema::hasColumn('audit_logs', 'source'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'access_grant_id'));
    }

    #[Test]
    public function exactly_twenty_eight_bmp_tables_exist(): void
    {
        $this->assertCount(28, self::BMP_TABLES);

        $all = collect(Schema::getTableListing())
            ->map(fn (string $t): string => str_contains($t, '.') ? explode('.', $t)[1] : $t);

        $unexpected = $all
            ->reject(fn (string $t): bool => in_array($t, self::BMP_TABLES, true))
            ->reject(fn (string $t): bool => in_array($t, self::FOUNDATION_TABLES, true))
            ->values();

        $this->assertSame([], $unexpected->all(), 'No table beyond the current phase may exist.');
    }

    #[Test]
    public function the_polymorphic_attachment_indexes_exist(): void
    {
        // Mandatory: without them there is no way to retrieve a subject's
        // attachments, since a polymorphic column carries no foreign key.
        $documentIndexes = collect(Schema::getIndexes('documents'))->map(fn (array $i): array => $i['columns']);
        $noteIndexes = collect(Schema::getIndexes('notes'))->map(fn (array $i): array => $i['columns']);

        $this->assertTrue($documentIndexes->contains(['documentable_type', 'documentable_id']));
        $this->assertTrue($noteIndexes->contains(['notable_type', 'notable_id']));
        // Visibility filtering on every external-facing read.
        $this->assertTrue($noteIndexes->contains(['is_internal']));
    }

    #[Test]
    public function attachments_carry_no_foreign_key_on_their_subject(): void
    {
        // Polymorphic by design. Ownership is resolved in application code,
        // which is why SubjectOwnership fails closed.
        foreach (Schema::getForeignKeys('documents') as $fk) {
            $this->assertNotContains('documentable_id', $fk['columns']);
        }

        foreach (Schema::getForeignKeys('notes') as $fk) {
            $this->assertNotContains('notable_id', $fk['columns']);
        }
    }

    #[Test]
    public function notes_carry_no_actor_triple(): void
    {
        // Notes are internal-only by construction: author_id is NOT NULL and
        // is always a user, so an external grant cannot author one.
        $this->assertTrue(Schema::hasColumn('notes', 'author_id'));
        $this->assertFalse(Schema::hasColumn('notes', 'source'));
        $this->assertFalse(Schema::hasColumn('notes', 'access_grant_id'));
        $this->assertFalse(Schema::hasColumn('notes', 'created_by'));

        // Documents DO carry it - they can arrive through a grant.
        foreach (['source', 'created_by', 'access_grant_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('documents', $column));
        }
    }

    #[Test]
    public function the_form_engine_constraints_are_database_constraints(): void
    {
        $unique = fn (string $table): Collection => collect(Schema::getIndexes($table))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns']);

        // Ambiguous history: two rows claiming to be "version 2" would make a
        // submission's provenance unresolvable.
        $this->assertTrue($unique('form_versions')->contains(['form_template_id', 'version_number']));
        // Deterministic rendering order.
        $this->assertTrue($unique('form_sections')->contains(['form_version_id', 'position']));
        $this->assertTrue($unique('questions')->contains(['form_version_id', 'position']));
        // One machine value per question.
        $this->assertTrue($unique('question_options')->contains(['question_id', 'value']));
        // One answer per question per submission.
        $this->assertTrue($unique('answers')->contains(['form_submission_id', 'question_id']));
        // The same choice cannot be selected twice.
        $this->assertTrue($unique('answer_options')->contains(['answer_id', 'question_option_id']));
    }

    #[Test]
    public function submission_scores_carry_no_unique_key(): void
    {
        // A unique key would forbid the recomputation history this table
        // exists to keep, and skill_area_id is nullable - which would
        // reintroduce the NULL-in-unique-index portability trap.
        $unique = collect(Schema::getIndexes('submission_scores'))
            ->filter(fn (array $i): bool => $i['unique'] === true && $i['columns'] !== ['id']);

        $this->assertCount(0, $unique);
    }

    #[Test]
    public function a_question_has_two_mandatory_parents(): void
    {
        $columns = collect(Schema::getColumns('questions'))->keyBy('name');

        $this->assertFalse($columns['form_version_id']['nullable'], 'form_version_id must be NOT NULL.');
        $this->assertFalse($columns['form_section_id']['nullable'], 'form_section_id must be NOT NULL.');

        $fks = collect(Schema::getForeignKeys('questions'))
            ->mapWithKeys(fn (array $fk): array => [$fk['columns'][0] => $fk['foreign_table']]);

        $this->assertSame('form_versions', $fks['form_version_id']);
        $this->assertSame('form_sections', $fks['form_section_id']);
    }

    #[Test]
    public function a_submission_binds_to_a_version_not_a_template(): void
    {
        $fks = collect(Schema::getForeignKeys('form_submissions'))
            ->mapWithKeys(fn (array $fk): array => [$fk['columns'][0] => $fk['foreign_table']]);

        $this->assertSame('form_versions', $fks['form_version_id']);
        $this->assertFalse(
            Schema::hasColumn('form_submissions', 'form_template_id'),
            'A submission must bind to a version; a template column would invite resolving "the current version".'
        );
    }

    #[Test]
    public function the_access_grant_token_hash_is_unique(): void
    {
        $indexes = collect(Schema::getIndexes('access_grants'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertTrue($indexes->contains(['token_hash']));
    }
}
