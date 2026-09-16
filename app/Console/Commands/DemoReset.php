<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuild the local demonstration dataset.
 *
 * THIS COMMAND DELETES DATA. Everything about it is arranged so that it cannot
 * do so anywhere it was not meant to:
 *
 *  - It runs only in local, development or testing. Every other environment,
 *    production included, is refused before a single row is touched. The check
 *    is an allowlist, not "if production", so an environment nobody thought of
 *    - staging, uat, demo-prod - is refused by default rather than wiped by
 *    default.
 *  - It asks before it deletes, unless --force is given.
 *  - It never runs migrate:fresh, so it cannot drop a schema or take the
 *    users table with it.
 *  - It clears only the domain tables listed below, child before parent.
 *    Users, roles and permissions are left alone, so the Super Admin created
 *    by SuperAdminSeeder survives and the operator stays logged in.
 *
 * Running it twice produces the same dataset, not two of everything: the wipe
 * happens first, and the demo team's logins are matched on their addresses.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Reset and reseed the local demonstration dataset (never runs outside local/development/testing)';

    /**
     * Domain tables, ordered child before parent.
     *
     * Ordering rather than disabling foreign keys: a delete that a constraint
     * would refuse is a delete in the wrong order, and finding that out is the
     * point of leaving the constraints on.
     *
     * @var list<string>
     */
    private const TABLES = [
        // Form engine: answers before submissions, submissions before versions.
        'answer_options',
        'answers',
        'submission_scores',
        'form_submissions',

        // Assignments.
        'assignment_reviews',
        'assignment_submissions',
        'assignment_instances',

        // Delivery.
        'session_attendances',
        'session_instances',
        'notification_dispatches',

        // Trackers.
        'day_plan_items',
        'time_grid_entries',
        'mmd_entries',
        'mmd_targets',
        'fund_plan_lines',
        'fund_plans',
        'action_items',

        // Business.
        'hr_policy_acknowledgements',
        'hr_policies',
        'positions',

        // Attachments and output.
        'documents',
        'notes',
        'report_artifacts',

        // External capability.
        'terms_acceptances',
        'access_grants',

        // AI.
        'ai_approvals',
        'ai_generations',
        'ai_prompt_versions',

        // The spine, then the businesses.
        'enrollments',
        'customer_contacts',
        'customers',

        // Curriculum and programme.
        'assignment_templates',
        'session_template_forms',
        'session_templates',
        'batches',
        'programs',

        // Form definitions.
        'question_options',
        'questions',
        'form_sections',
        'form_versions',
        'form_templates',
        'skill_areas',

        // The demo's own history. Cleared so the audit log shows this run and
        // not the last one; the services write it again as they seed.
        'audit_logs',
    ];

    public function handle(DemoSeeder $seeder): int
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->components->error(
                'demo:reset deletes data and runs only in local, development or testing. '
                .'This environment is ['.app()->environment().'], so nothing was changed.'
            );

            return self::FAILURE;
        }

        // Checked before the wipe, not during the seed: finding out that the
        // dataset cannot be attributed to anybody AFTER the old one has been
        // deleted would leave an empty database and a stack trace.
        if (! User::query()->exists()) {
            $this->components->error(
                'There is no user to attribute the demo data to. Run `php artisan db:seed` first, '
                .'which creates the Super Admin, then run this again. Nothing was changed.'
            );

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This deletes all customers, batches, forms and programme data in ['.app()->environment().']. Continue?'
        )) {
            $this->components->info('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->components->info('Clearing existing demo data…');
        $this->clear();
        $this->clearFiles();

        $this->components->info('Seeding the demonstration dataset…');
        $counts = $seeder->seed($this->output);

        $this->newLine();
        $this->components->info('Demo environment reset successfully.');
        $this->newLine();

        $rows = [];

        foreach ($counts as $label => $count) {
            $rows[] = [$label, number_format($count)];
        }

        $this->table(['Section', 'Records'], $rows);

        $this->newLine();
        $this->components->info(
            'Sign in with the Super Admin created by SuperAdminSeeder. '
            .'This command neither creates nor prints a password.'
        );

        return self::SUCCESS;
    }

    /**
     * Remove the files the demo itself writes.
     *
     * Their rows have just been deleted, so leaving the files would grow the
     * disk by a few megabytes on every reset with nothing pointing at them.
     * Only these two directories are touched: `documents/` holds whatever was
     * uploaded through the UI by hand, and deleting somebody's own upload
     * would be a worse surprise than a few orphaned bytes.
     */
    private function clearFiles(): void
    {
        $disk = Storage::disk('local');

        foreach (['demo-documents', 'reports'] as $directory) {
            if ($disk->exists($directory)) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    /**
     * Empty the domain tables, leaving users, roles and permissions intact.
     */
    private function clear(): void
    {
        DB::transaction(function (): void {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });
    }
}
