<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 1 schema: what must exist, and what must NOT exist yet.
 *
 * The negative assertions matter as much as the positive ones. A later-phase
 * table appearing early means the build order was skipped, and build order is
 * what keeps every phase independently shippable.
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const PHASE_ONE_TABLES = [
        'programs',
        'batches',
        'customers',
        'customer_contacts',
        'enrollments',
        'access_grants',
        'terms_acceptances',
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
        'documents', 'notes',
        'skill_areas', 'form_templates', 'form_versions', 'form_sections',
        'questions', 'question_options', 'form_submissions', 'answers',
        'answer_options', 'submission_scores',
        'session_templates', 'session_template_forms', 'session_instances', 'session_attendances',
        'assignment_templates', 'assignment_instances', 'assignment_submissions', 'assignment_reviews',
        'day_plan_items', 'time_grid_entries', 'mmd_entries', 'mmd_targets',
        'fund_plans', 'fund_plan_lines', 'action_items',
        'positions', 'hr_policies', 'hr_policy_acknowledgements',
        'notification_dispatches', 'report_artifacts',
        'ai_prompt_versions', 'ai_generations', 'ai_approvals',
        // Deferred client decisions - these must not appear at all.
        'end_customers', 'end_customer_interactions',
    ];

    #[Test]
    public function every_phase_one_table_exists(): void
    {
        foreach (self::PHASE_ONE_TABLES as $table) {
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
    public function the_phase_one_migrations_roll_back_and_re_apply(): void
    {
        // A migration that cannot be rolled back is a migration that cannot be
        // fixed in place on staging. Eight steps: seven tables plus A1.
        $this->artisan('migrate:rollback', ['--step' => 8])->assertSuccessful();

        foreach (self::PHASE_ONE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "[{$table}] should have been rolled back.");
        }

        // A1 reverses cleanly without disturbing the original audit columns.
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'source'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'access_grant_id'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'actor_id'));

        $this->artisan('migrate')->assertSuccessful();

        foreach (self::PHASE_ONE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "[{$table}] should have been re-applied.");
        }

        $this->assertTrue(Schema::hasColumn('audit_logs', 'source'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'access_grant_id'));
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
