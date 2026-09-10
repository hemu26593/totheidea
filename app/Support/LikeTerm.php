<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A LIKE search term that means the same thing on every engine.
 *
 * THE PROBLEM THIS EXISTS FOR. `LIKE` has no default escape character in
 * SQLite. MySQL uses a backslash. So the obvious escaping - replacing `%` with
 * `\%` - makes a literal `%` searchable on MySQL and silently unsearchable on
 * SQLite, where the backslash stays a backslash and the pattern then looks for
 * a character the data does not contain. Development is SQLite and production
 * is MySQL (ADR-005, ADR-006), which is exactly the pair of environments where
 * a difference like that goes unnoticed.
 *
 * THE FIX IS TO SAY WHICH CHARACTER ESCAPES. `LIKE ? ESCAPE '!'` is understood
 * identically by both engines, and stating it explicitly means the pattern no
 * longer depends on an engine default. `!` rather than `\` because a backslash
 * inside a MySQL string literal is itself escaped, so the same pattern would
 * need a different number of them in different places.
 *
 * The column is wrapped by the connection's own grammar, so the identifier is
 * quoted the way the engine in use expects and never interpolated by hand. The
 * term is a binding, never part of the SQL.
 */
final class LikeTerm
{
    /** The character that escapes the next one inside a pattern. */
    public const ESCAPE = '!';

    /**
     * A "contains" pattern in which every wildcard the user typed is a literal.
     *
     * The escape character is escaped FIRST, or escaping the wildcards would
     * then escape the escapes.
     */
    public static function contains(string $search): string
    {
        return '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $search,
        ).'%';
    }

    /**
     * Apply `<column> LIKE <term> ESCAPE '!'` to a query.
     *
     * @param  Builder<*>  $query
     */
    public static function where(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $sql = DB::connection($query->getConnection()->getName())
            ->getQueryGrammar()
            ->wrap($column)." like ? escape '".self::ESCAPE."'";

        $query->whereRaw($sql, [$term], $boolean);
    }

    /**
     * The `or` form, for the second and later columns of a search.
     *
     * @param  Builder<*>  $query
     */
    public static function orWhere(Builder $query, string $column, string $term): void
    {
        self::where($query, $column, $term, 'or');
    }
}
