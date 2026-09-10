<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Access\AccessGrantService;
use App\Domain\Trackers\MmdEntryService;
use App\Domain\Trackers\TargetVsActualCalculator;
use App\Enums\AuditAction;
use App\Enums\GrantAbility;
use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\MmdEntry;
use App\Models\Program;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Portability guards for the gap that actually bites: development and CI run on
 * SQLite (ADR-005) and production runs on MySQL 8 (ADR-006). A schema or a query
 * that only SQLite tolerates passes every test and then fails in production.
 *
 * EVERY TEST HERE RUNS ON WHICHEVER ENGINE IS CONFIGURED. They use Laravel's
 * own schema introspection rather than sqlite_master, so the same file is the
 * SQLite guard on the default run and a live assertion under
 * `./vendor/bin/phpunit -c phpunit.mysql.xml`. Nothing here needs MySQL to be
 * installed for the normal suite to pass.
 *
 * See docs/mysql-compatibility.md.
 */
class MysqlCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** MySQL's identifier ceiling. Exceeding it is ERROR 1059 at migrate time. */
    private const IDENTIFIER_LIMIT = 64;

    /**
     * MySQL 8.0 reserved words plausible as an alias or an unquoted identifier
     * here. Laravel's grammar back-tick wraps everything it builds, so the
     * exposure is raw fragments - selectRaw, orderByRaw, aliases - where
     * nothing wraps them for you.
     */
    private const RESERVED = [
        'rows', 'row', 'range', 'groups', 'rank', 'over', 'window', 'lead', 'lag',
        'system', 'recursive', 'lateral', 'key', 'keys', 'index', 'order', 'group',
        'read', 'write', 'call', 'condition', 'exit', 'leave', 'loop', 'repeat',
        'while', 'partition', 'release', 'rename', 'table', 'usage', 'using',
        'values', 'when', 'interval', 'match', 'natural', 'lock', 'option',
        'primary', 'procedure', 'references', 'specific', 'sql', 'to', 'union',
        'unique', 'use', 'varying', 'add', 'all', 'alter', 'and', 'as', 'asc',
        'between', 'by', 'case', 'check', 'column', 'constraint', 'create',
        'cursor', 'database', 'default', 'delete', 'desc', 'distinct', 'drop',
        'else', 'exists', 'false', 'fetch', 'for', 'foreign', 'from', 'having',
        'if', 'ignore', 'in', 'inner', 'insert', 'into', 'is', 'join', 'left',
        'like', 'limit', 'not', 'null', 'on', 'or', 'outer', 'replace', 'right',
        'select', 'set', 'then', 'true', 'update', 'where', 'with',
    ];

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_migration_runs_and_produces_the_whole_schema(): void
    {
        // RefreshDatabase has already migrated. That this test runs at all is
        // the assertion when the connection is MySQL.
        $tables = $this->tables();

        $this->assertGreaterThan(40, count($tables), 'The BMP schema is 40+ tables; a short list means migrations stopped early.');

        foreach (['customers', 'enrollments', 'form_submissions', 'mmd_entries', 'access_grants', 'audit_logs'] as $expected) {
            $this->assertContains($expected, $tables);
        }

        $this->assertSame(0, DB::table('migrations')->where('batch', 0)->count());
    }

    #[Test]
    public function no_table_index_or_column_name_exceeds_mysqls_identifier_limit(): void
    {
        $offenders = [];

        foreach ($this->tables() as $table) {
            if (strlen($table) > self::IDENTIFIER_LIMIT) {
                $offenders[] = "table {$table} (".strlen($table).')';
            }

            foreach (Schema::getColumns($table) as $column) {
                if (strlen((string) $column['name']) > self::IDENTIFIER_LIMIT) {
                    $offenders[] = "column {$table}.{$column['name']}";
                }
            }

            foreach (Schema::getIndexes($table) as $index) {
                if (strlen((string) $index['name']) > self::IDENTIFIER_LIMIT) {
                    $offenders[] = "index {$index['name']} (".strlen((string) $index['name']).", on {$table})";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "MySQL rejects any identifier longer than 64 characters (ERROR 1059). These would fail at\n"
            ."migrate time on production while creating happily on SQLite. Pass an explicit short name\n"
            .'as the second argument to unique() or index().',
        );
    }

    #[Test]
    public function no_index_is_wide_enough_to_break_innodbs_key_limit(): void
    {
        // InnoDB caps an index key at 3072 bytes, and utf8mb4 is 4 bytes per
        // character - so a composite index over long strings can be legal on
        // SQLite and rejected by MySQL.
        $offenders = [];

        foreach ($this->tables() as $table) {
            $widths = [];

            foreach (Schema::getColumns($table) as $column) {
                $widths[$column['name']] = $this->indexByteWidth((string) $column['type']);
            }

            foreach (Schema::getIndexes($table) as $index) {
                $bytes = 0;

                foreach ($index['columns'] as $column) {
                    $bytes += $widths[$column] ?? 8;
                }

                if ($bytes > 3072) {
                    $offenders[] = "{$table}.{$index['name']} ~{$bytes} bytes";
                }
            }
        }

        $this->assertSame([], $offenders, 'InnoDB refuses an index key wider than 3072 bytes.');
    }

    #[Test]
    public function no_column_is_a_database_enum(): void
    {
        // The architecture stores statuses as strings with PHP enum casts. A
        // database ENUM would pin the value list into the schema, and changing
        // one means an ALTER on a big table.
        $offenders = [];

        foreach ($this->tables() as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if (str_starts_with(strtolower((string) $column['type']), 'enum')) {
                    $offenders[] = "{$table}.{$column['name']}";
                }
            }
        }

        $this->assertSame([], $offenders, 'Statuses are strings plus PHP enum casts; a database ENUM is not portable.');
    }

    #[Test]
    public function every_foreign_key_points_at_a_column_that_exists(): void
    {
        $offenders = [];
        $tables = $this->tables();

        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $target = (string) $fk['foreign_table'];

                if (! in_array($target, $tables, true)) {
                    $offenders[] = "{$table} -> {$target} (missing table)";

                    continue;
                }

                $targetColumns = array_column(Schema::getColumns($target), 'name');

                foreach ($fk['foreign_columns'] as $column) {
                    if (! in_array($column, $targetColumns, true)) {
                        $offenders[] = "{$table} -> {$target}.{$column} (missing column)";
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function financial_columns_keep_their_precision_and_scale(): void
    {
        // MySQL enforces DECIMAL(p,s); SQLite ignores it entirely, so a wrong
        // scale is invisible until production silently rounds money.
        $expected = [
            'mmd_entries.fund_in' => [14, 2],
            'mmd_entries.fund_out' => [14, 2],
            'mmd_entries.sales_closed_value' => [14, 2],
            'mmd_targets.target_value' => [14, 2],
            'fund_plans.budget_total' => [14, 2],
            'fund_plan_lines.planned_amount' => [14, 2],
            'fund_plan_lines.actual_amount' => [14, 2],
            'answers.value_number' => [14, 4],
            'submission_scores.raw_score' => [10, 2],
            'submission_scores.max_score' => [10, 2],
            'time_grid_entries.planned_hours' => [7, 2],
        ];

        // The migration is the portable source of truth: SQLite discards
        // precision entirely and reports the column as plain `numeric`, so a
        // wrong scale is invisible there and silently rounds money in MySQL.
        $migrations = '';

        foreach (Finder::create()->files()->in(base_path('database/migrations'))->name('*.php') as $file) {
            $migrations .= $file->getContents();
        }

        foreach ($expected as $path => [$precision, $scale]) {
            [$table, $column] = explode('.', $path);

            $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);
            $this->assertNotNull($definition, "[{$path}] is missing from the built schema.");

            $this->assertMatchesRegularExpression(
                "/->decimal\(\s*'{$column}'\s*,\s*{$precision}\s*,\s*{$scale}\s*\)/",
                $migrations,
                "[{$path}] must be declared decimal({$precision},{$scale}) in its migration.",
            );

            // Where the engine records precision - MySQL does, SQLite does not
            // - assert the built column agrees with the declaration.
            $type = (string) $definition['type'];

            if (preg_match('/\(\d+\s*,\s*\d+\)/', $type) === 1) {
                $this->assertMatchesRegularExpression(
                    '/^(decimal|numeric)\('.$precision.'\s*,\s*'.$scale.'\)$/i',
                    $type,
                    "[{$path}] was built as [{$type}] rather than decimal({$precision},{$scale}).",
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_mmd_actual_query_is_portable_and_uses_no_reserved_alias(): void
    {
        $customer = Customer::factory()->create();

        foreach ([1000, 250.55] as $index => $amount) {
            MmdEntry::factory()->create([
                'customer_id' => $customer->getKey(),
                'entry_date' => '2026-09-0'.($index + 1),
                'fund_in' => $amount,
            ]);
        }

        DB::enableQueryLog();

        $actual = app(TargetVsActualCalculator::class)
            ->actual($customer, 'fund_in', '2026-09-01', '2026-09-30');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Exact to the paisa. A float sum would land on 1250.5499999.
        $this->assertSame('1250.55', number_format((float) $actual, 2, '.', ''));

        foreach ($queries as $query) {
            foreach ($this->aliasesIn((string) $query['query']) as $alias) {
                $this->assertNotContains(
                    $alias,
                    self::RESERVED,
                    "The alias [{$alias}] is a MySQL reserved word. SQLite accepts it; MySQL 8 raises a "
                    .'syntax error, so the query would pass every test and fail in production.',
                );
            }
        }
    }

    #[Test]
    public function a_period_with_no_entries_reports_nothing_rather_than_zero(): void
    {
        $customer = Customer::factory()->create();

        // Null and zero are different statements about a business and must not
        // be conflated by either engine's aggregate behaviour.
        $this->assertNull(
            app(TargetVsActualCalculator::class)->actual($customer, 'fund_in', '2026-01-01', '2026-01-31'),
        );
    }

    #[Test]
    public function aggregate_queries_survive_only_full_group_by(): void
    {
        // MySQL 8 enables ONLY_FULL_GROUP_BY by default: any selected column
        // that is neither aggregated nor grouped is an error. SQLite invents a
        // value instead, so the bug is invisible there.
        $customer = Customer::factory()->create();

        MmdEntry::factory()->count(3)->create([
            'customer_id' => $customer->getKey(),
            'entry_date' => '2026-09-01',
        ]);

        $byStatus = DB::table('mmd_entries')
            ->selectRaw('customer_id, count(*) as total')
            ->groupBy('customer_id')
            ->get();

        $this->assertCount(1, $byStatus);
        $this->assertSame(3, (int) $byStatus->first()->total);
    }

    #[Test]
    public function the_dashboards_exists_subqueries_run_on_either_engine(): void
    {
        // whereExists with selectRaw('1') is the portable shape; a HAVING over
        // a withCount alias without a GROUP BY is not, and is what this
        // replaced.
        $customer = Customer::factory()->create();

        $found = Customer::query()
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('customers as c2')
                ->whereColumn('c2.id', 'customers.id'))
            ->pluck('id');

        $this->assertTrue($found->contains($customer->getKey()));
    }

    #[Test]
    public function counting_and_paginating_a_scoped_query_is_portable(): void
    {
        Customer::factory()->count(7)->create();

        $page = Customer::query()->orderBy('id')->paginate(3);

        $this->assertSame(7, $page->total());
        $this->assertSame(3, $page->perPage());
        $this->assertCount(3, $page->items());

        // The count query must not carry an aggregate alias MySQL reserves.
        DB::enableQueryLog();
        Customer::query()->orderBy('name')->count();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            foreach ($this->aliasesIn((string) $query['query']) as $alias) {
                $this->assertNotContains($alias, self::RESERVED);
            }
        }
    }

    #[Test]
    public function a_boolean_round_trips_as_a_boolean_on_either_engine(): void
    {
        // MySQL stores a boolean as TINYINT(1) and hands back 0/1; SQLite hands
        // back 0/1 too, but an un-cast column would compare differently. The
        // cast is what makes `false` survive.
        $customer = Customer::factory()->create();

        $entry = MmdEntry::factory()->create([
            'customer_id' => $customer->getKey(),
            'entry_date' => '2026-09-01',
        ]);

        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'is_primary' => false,
        ]);

        $this->assertIsBool($contact->fresh()->is_primary);
        $this->assertFalse($contact->fresh()->is_primary);

        $contact->forceFill(['is_primary' => true])->save();
        $this->assertTrue($contact->fresh()->is_primary);

        // And a false is found by a where, rather than being read as null.
        $this->assertSame(
            0,
            CustomerContact::query()
                ->where('customer_id', $customer->getKey())
                ->where('is_primary', false)
                ->count(),
        );

        $this->assertNotNull($entry->fresh());
    }

    #[Test]
    public function a_json_column_round_trips_including_null_and_empty(): void
    {
        $actor = $this->seedAuthorizationAndReturnAdmin();

        $customer = Customer::factory()->create();

        app(AuditLogger::class)->log(
            AuditAction::CustomerCreated,
            $customer,
            null,
            ['name' => 'Alpha', 'nested' => ['a' => 1, 'b' => null], 'empty' => []],
            $actor,
        );

        $entry = AuditLog::query()->latest('id')->first();

        $this->assertNull($entry->old_values, 'A null JSON column must read back as null, not as "null".');
        $this->assertIsArray($entry->new_values);
        $this->assertSame('Alpha', $entry->new_values['name']);
        $this->assertSame(1, $entry->new_values['nested']['a']);
        $this->assertNull($entry->new_values['nested']['b']);
        $this->assertSame([], $entry->new_values['empty']);
    }

    #[Test]
    public function date_filtering_uses_portable_comparisons(): void
    {
        $customer = Customer::factory()->create();

        foreach (['2026-08-31', '2026-09-01', '2026-09-30', '2026-10-01'] as $date) {
            MmdEntry::factory()->create(['customer_id' => $customer->getKey(), 'entry_date' => $date]);
        }

        // whereDate/whereBetween compile to each engine's own date handling;
        // a strftime() or a string comparison would not.
        $this->assertSame(2, MmdEntry::query()
            ->where('customer_id', $customer->getKey())
            ->whereDate('entry_date', '>=', '2026-09-01')
            ->whereDate('entry_date', '<=', '2026-09-30')
            ->count());

        $this->assertSame(3, MmdEntry::query()
            ->where('customer_id', $customer->getKey())
            ->whereYear('entry_date', 2026)
            ->whereMonth('entry_date', 9)
            ->count() + 1);
    }

    /**
     * FIXED HERE. All three searches escaped wildcards with a backslash, which
     * is MySQL's default LIKE escape character and is not an escape character
     * at all in SQLite - so a literal `%` typed into the box matched everything
     * on production and nothing in development. LikeTerm states the escape
     * character explicitly, which both engines honour identically.
     */
    #[Test]
    public function every_search_treats_a_typed_wildcard_as_a_literal_on_either_engine(): void
    {
        $this->seedAuthorization();

        Customer::factory()->create(['name' => 'Alpha 100% Metals', 'code' => 'C-PCT']);
        Customer::factory()->create(['name' => 'Alpha Under_score', 'code' => 'C-USC']);
        Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        $directory = app(CustomerDirectory::class);

        // A literal % must find the one row that contains it, not every row.
        $this->assertSame(1, $directory->query('100%')->count());
        $this->assertSame(1, $directory->query('Under_score')->count());

        // An underscore is a single-character wildcard if it is not escaped,
        // so this would otherwise also match "Under-score", "Underxscore"...
        $this->assertSame(0, $directory->query('Under?score')->count());

        // Ordinary searching still works.
        $this->assertSame(2, $directory->query('Alpha')->count());
        $this->assertSame(1, $directory->query('Beta')->count());
        $this->assertSame(1, $directory->query('C-PCT')->count());
        $this->assertSame(3, $directory->query('')->count());
    }

    #[Test]
    public function the_user_and_batch_searches_escape_wildcards_too(): void
    {
        $this->seedAuthorization();

        User::factory()->create(['name' => 'Percent 50% Person', 'email' => 'pct@example.test']);
        User::factory()->create(['name' => 'Ordinary Person', 'email' => 'ord@example.test']);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('search', '50%')
            ->assertSee('Percent 50% Person')
            ->assertDontSee('Ordinary Person');

        $program = Program::factory()->create(['session_count' => 6]);
        Batch::factory()->create(['program_id' => $program->getKey(), 'name' => 'Batch 10% Pilot', 'code' => 'B-PCT']);
        Batch::factory()->create(['program_id' => $program->getKey(), 'name' => 'Ordinary Batch', 'code' => 'B-ORD']);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Batches\Index::class)
            ->set('search', '10%')
            ->assertSee('Batch 10% Pilot')
            ->assertDontSee('Ordinary Batch');
    }

    #[Test]
    public function no_search_relies_on_an_engines_default_like_escape(): void
    {
        // The regression guard: a backslash-escaped LIKE term is the shape that
        // works on MySQL and silently fails on SQLite.
        $offenders = [];

        foreach ($this->applicationSources() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            $relative = str_replace(base_path().'/', '', $file);

            if (preg_match("/str_replace\s*\(\s*\[[^\]]*'%'[^\]]*\]\s*,\s*\[[^\]]*\\\\%/", $code) === 1) {
                $offenders[] = $relative;
            }

            if (preg_match("/'like'\s*,/", $code) === 1 && ! str_contains($code, 'LikeTerm')) {
                $offenders[] = $relative.' (bare like without LikeTerm)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Escape the pattern through App\Support\LikeTerm, which states the escape character.',
        );
    }

    #[Test]
    public function customer_isolation_scoping_is_portable(): void
    {
        $alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        MmdEntry::factory()->count(2)->create(['customer_id' => $alpha->getKey(), 'entry_date' => '2026-09-01']);
        MmdEntry::factory()->create(['customer_id' => $beta->getKey(), 'entry_date' => '2026-09-01']);

        $this->assertSame(2, MmdEntry::query()->where('customer_id', $alpha->getKey())->count());
        $this->assertSame(1, MmdEntry::query()->where('customer_id', $beta->getKey())->count());

        $this->assertSame(
            [$alpha->getKey()],
            MmdEntry::query()->where('customer_id', $alpha->getKey())
                ->pluck('customer_id')->unique()->values()->all(),
        );
    }

    #[Test]
    public function the_mmd_optimistic_lock_increments_on_either_engine(): void
    {
        // DB::raw('lock_version + 1') is ANSI and portable, but the read-back
        // is what proves it, and a conditional UPDATE behaves differently under
        // MySQL's row locking.
        $customer = Customer::factory()->create();
        $service = app(MmdEntryService::class);

        $this->assertNotNull($service);
        $entry = MmdEntry::factory()->create([
            'customer_id' => $customer->getKey(),
            'entry_date' => '2026-09-01',
            'lock_version' => 0,
        ]);

        DB::table('mmd_entries')->where('id', $entry->getKey())->update([
            'lock_version' => DB::raw('lock_version + 1'),
        ]);

        $this->assertSame(1, (int) $entry->fresh()->lock_version);
    }

    #[Test]
    public function the_connection_pins_a_timezone_so_a_timestamp_means_one_instant(): void
    {
        // MySQL converts a TIMESTAMP between the session zone and UTC on every
        // read and write; SQLite does not convert at all. Left at SYSTEM, all
        // 140 TIMESTAMP columns in this schema shift whenever the machine's
        // zone does - a DST change, a host move, or a dump restored elsewhere.
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('UTC', config('app.timezone'));

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertSame('+00:00', DB::select('SELECT @@session.time_zone AS tz')[0]->tz);
        }
    }

    #[Test]
    public function a_stored_moment_reads_back_as_the_moment_it_was_written(): void
    {
        $customer = Customer::factory()->create();

        $moment = CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC');

        $entry = MmdEntry::factory()->create([
            'customer_id' => $customer->getKey(),
            'entry_date' => '2026-09-09',
        ]);

        $entry->forceFill(['created_at' => $moment])->save();

        $this->assertSame(
            '2026-09-09 12:00:00',
            $entry->fresh()->created_at->utc()->format('Y-m-d H:i:s'),
            'A timestamp must survive the round trip unshifted on either engine.',
        );

        // The date column too - a DATE has no zone and must not acquire one.
        $this->assertSame('2026-09-09', $entry->fresh()->entry_date->toDateString());
    }

    /**
     * Recorded, not "fixed". MySQL's utf8mb4_unicode_ci makes string equality
     * and unique constraints case-INSENSITIVE where SQLite's are case-sensitive.
     * MySQL is the STRICTER engine in every one of those directions - it refuses
     * a near-duplicate code that SQLite accepts - so nothing becomes reachable
     * in production that was not reachable in development. Forcing a binary
     * collation to match SQLite would be the worse choice: it would let
     * `C-ALPHA` and `c-alpha` exist as two different businesses.
     */
    #[Test]
    public function collation_differences_never_widen_what_a_query_can_reach(): void
    {
        $alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        // Whatever the collation, one business's code never selects another's.
        foreach (['C-ALPHA', 'c-alpha', 'C-Alpha'] as $probe) {
            $found = Customer::query()->where('code', $probe)->pluck('id');

            $this->assertFalse(
                $found->contains($beta->getKey()),
                "Searching for [{$probe}] reached another business.",
            );
        }

        $this->assertTrue(Customer::query()->where('code', 'C-ALPHA')->pluck('id')->contains($alpha->getKey()));

        // And a scoped query is unaffected by case in either direction.
        $this->assertSame(0, Customer::query()->where('code', 'C-GAMMA')->count());
    }

    #[Test]
    public function an_access_grant_token_is_refused_whatever_its_case(): void
    {
        // The stored value is a lowercase SHA-256 hex digest and the caller
        // supplies the PLAINTEXT, never the hash - so a case-insensitive
        // collation on token_hash gives an attacker nothing. Asserted rather
        // than assumed, because the column is compared with a _ci collation.
        $this->seedAuthorization();

        $customer = Customer::factory()->create();
        $program = Program::factory()->create(['session_count' => 6]);
        $batch = Batch::factory()->create(['program_id' => $program->getKey()]);
        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        $issued = app(AccessGrantService::class)->issue(
            customer: $customer,
            enrollment: $enrollment,
            subject: $enrollment,
            ability: GrantAbility::AcceptTerms,
            expiresAt: now()->addDay(),
            actor: $this->admin(),
        );

        $service = app(AccessGrantService::class);
        $token = $issued->plaintextToken;

        $this->assertNotNull($service->findByToken($token));

        // A token differing only in case is a DIFFERENT token: it hashes to a
        // different digest, so it resolves to nothing on either engine.
        $flipped = $token === strtoupper($token) ? strtolower($token) : strtoupper($token);

        $this->assertNotSame($token, $flipped);
        $this->assertNull($service->findByToken($flipped), 'A case-flipped token must not resolve.');
    }

    /*
    |--------------------------------------------------------------------------
    | Source-level guards
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function no_sqlite_only_sql_appears_anywhere_in_the_application(): void
    {
        $sqliteOnly = [
            'strftime' => 'SQLite date function; MySQL has no strftime.',
            'julianday' => 'SQLite date function.',
            'sqlite_master' => 'SQLite catalogue table; use Schema::getTables().',
            'PRAGMA ' => 'SQLite pragma.',
            'IFNULL(' => 'Portable, but COALESCE is the ANSI spelling both engines share.',
            'AUTOINCREMENT' => 'SQLite spelling; MySQL is AUTO_INCREMENT.',
            'RANDOM()' => 'SQLite spelling; MySQL is RAND().',
            '||' => null,
        ];

        $offenders = [];

        foreach ($this->applicationSources() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            $relative = str_replace(base_path().'/', '', $file);

            foreach ($sqliteOnly as $needle => $why) {
                // `||` is PHP's or-operator far more often than SQL string
                // concatenation, so it is only meaningful inside a raw fragment.
                if ($needle === '||') {
                    if (preg_match('/(selectRaw|whereRaw|orderByRaw|havingRaw|DB::raw)\s*\([^)]*\|\|/', $code) === 1) {
                        $offenders[] = "{$relative}: SQL string concatenation with || inside a raw fragment (MySQL needs CONCAT).";
                    }

                    continue;
                }

                if (stripos($code, $needle) !== false) {
                    $offenders[] = "{$relative}: {$needle} — {$why}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function every_raw_fragment_is_free_of_reserved_aliases(): void
    {
        $offenders = [];

        foreach ($this->applicationSources() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            $relative = str_replace(base_path().'/', '', $file);

            preg_match_all(
                '/(?:selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw|DB::raw)\s*\(\s*[\'"]([^\'"]*)[\'"]/',
                $code,
                $matches,
            );

            foreach ($matches[1] as $fragment) {
                foreach ($this->aliasesIn($fragment) as $alias) {
                    if (in_array($alias, self::RESERVED, true)) {
                        $offenders[] = "{$relative}: alias [{$alias}] in raw SQL is a MySQL reserved word.";
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function raw_fragments_never_interpolate_a_variable_without_wrapping_it(): void
    {
        // A raw fragment built from a variable is both an injection risk and a
        // portability one: only the grammar knows how to quote an identifier
        // for the engine in use.
        $offenders = [];

        foreach ($this->applicationSources() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            $relative = str_replace(base_path().'/', '', $file);

            preg_match_all(
                '/(?:selectRaw|whereRaw|orderByRaw|havingRaw|groupByRaw|DB::raw)\s*\(\s*([^;]{0,400}?)\)/s',
                $code,
                $matches,
            );

            foreach ($matches[1] as $argument) {
                $interpolates = preg_match('/\$\w+/', $argument) === 1
                    || str_contains($argument, '{$');

                if (! $interpolates) {
                    continue;
                }

                // Acceptable when the identifier went through the grammar's
                // wrap(), which is the only thing that knows how the engine in
                // use quotes a name. The wrap may be on an earlier line - the
                // fragment is often assembled into a variable first - so the
                // sanctioned patterns are recognised file-wide rather than
                // inside the argument text.
                $wrapped = str_contains($argument, 'wrap(')
                    || str_contains($argument, '$this->column(')
                    // The call is often broken across lines
                    // (->getQueryGrammar()\n->wrap($column)), so match the
                    // wrap itself rather than the whole chain.
                    || str_contains($code, '->wrap(');

                if ($wrapped) {
                    continue;
                }

                $offenders[] = "{$relative}: raw SQL interpolates a variable without grammar wrapping — {$argument}";
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function no_seeder_or_factory_reads_env_directly(): void
    {
        // `config:cache` stops Laravel loading .env, so env() outside config/
        // silently returns its default from that point on - and a deploy that
        // caches before seeding is the usual order.
        $offenders = [];

        $finder = Finder::create()->files()->in(base_path('database'))->name('*.php');

        foreach ($finder as $file) {
            $code = $this->withoutComments((string) $file->getContents());

            if (preg_match('/(?<![>\w])env\s*\(/', $code) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Seeders and factories must read config(), never env().');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        $names = collect(Schema::getTables())
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            // MySQL returns schema-qualified names on some versions.
            ->map(fn (string $name): string => str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name)
            ->reject(fn (string $name): bool => $name === 'migrations')
            ->values()
            ->all();

        sort($names);

        return $names;
    }

    private function indexByteWidth(string $type): int
    {
        if (preg_match('/(?:var)?char\((\d+)\)/i', $type, $matches) === 1) {
            return ((int) $matches[1]) * 4; // utf8mb4
        }

        if (stripos($type, 'text') !== false || stripos($type, 'blob') !== false) {
            return 65535;
        }

        return 8;
    }

    /**
     * Every `... as <name>` alias in a fragment.
     *
     * @return array<int, string>
     */
    private function aliasesIn(string $sql): array
    {
        preg_match_all('/\bas\s+["`\']?([a-z_][a-z0-9_]*)["`\']?/i', $sql, $matches);

        return array_map('strtolower', $matches[1]);
    }

    private function withoutComments(string $source): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
    }

    /**
     * @return array<int, string>
     */
    private function applicationSources(): array
    {
        $files = [];

        foreach (Finder::create()->files()->in([base_path('app'), base_path('database')])->name('*.php') as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function seedAuthorizationAndReturnAdmin(): User
    {
        $this->seedAuthorization();

        return $this->admin();
    }
}
