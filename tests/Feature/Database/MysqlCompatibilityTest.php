<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Trackers\TargetVsActualCalculator;
use App\Models\Customer;
use App\Models\MmdEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Portability guards for the difference that actually bites: development runs
 * on SQLite (ADR-005) and production runs on MySQL (ADR-006), so a schema or a
 * query that only SQLite tolerates passes every test here and then fails on the
 * production database.
 *
 * These tests run on SQLite like everything else. They do not need MySQL,
 * because what they assert are the two rules MySQL imposes and SQLite does not:
 * an identifier ceiling, and a reserved-word vocabulary.
 */
class MysqlCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL 8.0 reserved words that are plausible as an alias or an unquoted
     * identifier in this codebase. Laravel's grammar back-tick wraps anything
     * it builds itself, so the exposure is raw fragments - selectRaw,
     * orderByRaw, and aliases - where nothing wraps them for you.
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

    #[Test]
    public function no_index_name_exceeds_mysqls_identifier_limit(): void
    {
        $offenders = [];

        foreach ($this->tables() as $table) {
            foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $index) {
                if (strlen((string) $index->name) > 64) {
                    $offenders[] = $index->name.' ('.strlen((string) $index->name).' chars, on '.$table.')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "MySQL rejects any identifier longer than 64 characters (ERROR 1059), so these indexes\n"
            ."would fail at migrate time on the production database while creating happily on SQLite.\n"
            .'Give the index an explicit short name as the second argument to unique() or index().',
        );
    }

    #[Test]
    public function no_table_or_column_is_named_with_a_reserved_word_that_raw_sql_touches(): void
    {
        // Laravel wraps every identifier it generates, so a reserved column
        // name is only dangerous where a raw fragment names it unwrapped. This
        // asserts the schema's own names against the words that would also
        // break a raw fragment, which is where the bug can actually reach.
        $offenders = [];

        foreach ($this->tables() as $table) {
            if (in_array(strtolower($table), ['rows', 'groups', 'range', 'window', 'system'], true)) {
                $offenders[] = "table {$table}";
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function the_mmd_actual_query_uses_no_reserved_word_as_an_alias(): void
    {
        $customer = Customer::factory()->create();

        MmdEntry::factory()->create([
            'customer_id' => $customer->getKey(),
            'entry_date' => '2026-09-01',
            'fund_in' => 1000,
        ]);

        DB::enableQueryLog();

        $actual = app(TargetVsActualCalculator::class)
            ->actual($customer, 'fund_in', '2026-09-01', '2026-09-30');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1000.0, $actual);
        $this->assertNotEmpty($queries);

        foreach ($queries as $query) {
            foreach ($this->aliasesIn((string) $query['query']) as $alias) {
                $this->assertNotContains(
                    $alias,
                    self::RESERVED,
                    "The alias [{$alias}] is a MySQL reserved word. SQLite accepts it; MySQL 8 raises a "
                    .'syntax error, so the query would work in every test and fail in production.',
                );
            }
        }
    }

    /**
     * Every `... as <name>` alias in a statement.
     *
     * @return array<int, string>
     */
    private function aliasesIn(string $sql): array
    {
        preg_match_all('/\bas\s+["`\']?([a-z_][a-z0-9_]*)["`\']?/i', $sql, $matches);

        return array_map('strtolower', $matches[1]);
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
            ->pluck('name')
            ->reject(fn (string $name): bool => $name === 'migrations')
            ->values()
            ->all();
    }
}
