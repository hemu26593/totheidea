# MySQL Compatibility

The BMP Digital Platform runs on **SQLite in development and test** (ADR-005)
and **MySQL 8 in production** (ADR-006). Both are supported first-class: setting
`DB_CONNECTION=mysql` requires no code change.

This document records what differs between the two engines, what was fixed to
make them agree, and how to verify it.

---

## 1. Verification status

The entire test suite has been executed against a **live MySQL 8.0.46** server
with `ONLY_FULL_GROUP_BY` and `STRICT_TRANS_TABLES` enabled — MySQL's own
defaults and the strictest realistic production configuration.

| | |
|---|---|
| Engine | MySQL 8.0.46, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` |
| SQL mode | `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` |
| `migrate:fresh --seed` | all 49 migrations and both seeders |
| Full PHPUnit suite | **1122 tests, all passing** |
| Browser walkthrough | 26 modules × Admin and Staff, plus the external participant form, against a MySQL-backed app |

This is live execution, not a static analysis. Nothing below is claimed as
verified on the basis of reading code alone.

## 2. Running the compatibility suite

The default run stays on SQLite and needs no MySQL:

```bash
php artisan test          # or ./vendor/bin/phpunit
```

`phpunit.mysql.xml` runs the *same tests* against MySQL. It differs from
`phpunit.xml` in the database settings only.

```bash
# One-time: a disposable database and a user for it. Choose your own password;
# none is committed to this repository.
mysql -uroot -e "CREATE DATABASE bmp_compat CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
                 CREATE USER 'bmp'@'127.0.0.1' IDENTIFIED BY '<choose-one>';
                 GRANT ALL ON bmp_compat.* TO 'bmp'@'127.0.0.1';"

DB_USERNAME=bmp DB_PASSWORD='<the-one-you-chose>' ./vendor/bin/phpunit -c phpunit.mysql.xml
```

PHPUnit does not overwrite an environment variable that is already set, so the
values in `phpunit.mysql.xml` are defaults the command line overrides — which is
why the file carries a database name but no credential.

The database it names is **disposable** and is rebuilt on every run. Never point
it at anything you care about.

To run the application itself against MySQL without touching `.env`:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=bmp_compat \
DB_USERNAME=bmp DB_PASSWORD='<the-one-you-chose>' \
php artisan migrate:fresh --seed
```

`tests/Feature/Database/MysqlCompatibilityTest.php` runs under **both** engines.
It uses Laravel's schema introspection rather than `sqlite_master`, so on the
default run it guards against reintroducing a MySQL-breaking change, and under
`phpunit.mysql.xml` it asserts the same things against the real thing.

## 3. Fixes made for MySQL

### 3.1 Identifier length — would have broken `migrate` outright

MySQL rejects any identifier longer than 64 characters (`ERROR 1059`). Three
auto-generated unique-index names were 65–66 characters, so the very first
production `migrate` would have failed. They now carry explicit short names.

Guarded by `no_table_index_or_column_name_exceeds_mysqls_identifier_limit`.

### 3.2 Reserved word as an alias — a syntax error on MySQL only

The MMD target-vs-actual query aliased a count as `rows`. `ROWS` is reserved in
MySQL 8.0 (it is the window frame clause), so the query is a syntax error there
and perfectly valid in SQLite. Renamed to `entry_count`.

Guarded by `the_mmd_actual_query_is_portable_and_uses_no_reserved_alias` and, at
source level, by `every_raw_fragment_is_free_of_reserved_aliases`.

### 3.3 `LIKE` escaping — behaved differently in each environment

All three search boxes (customers, users, batches) escaped wildcards with a
backslash. **MySQL treats `\` as the default `LIKE` escape character; SQLite has
no default escape character at all.** So a literal `%` typed into the box matched
the intended row in production and *nothing* in development — the same code, two
behaviours.

`App\Support\LikeTerm` now states the escape character explicitly
(`LIKE ? ESCAPE '!'`), which both engines honour identically, and escapes `_` as
well as `%` — the user search previously escaped only `%`, so an underscore was a
single-character wildcard on both engines.

Guarded by `every_search_treats_a_typed_wildcard_as_a_literal_on_either_engine`,
`the_user_and_batch_searches_escape_wildcards_too`, and
`no_search_relies_on_an_engines_default_like_escape`.

### 3.4 Connection timezone — 140 columns that could shift

MySQL converts a `TIMESTAMP` column between the session time zone and UTC on
every read and write. SQLite does no conversion. `$table->timestamps()` produces
`TIMESTAMP` columns, and this schema has **140** of them.

With the session left at `SYSTEM`, the stored instant depends on the machine's
zone at the moment of the write. It round-trips correctly while that zone never
changes — and shifts every one of those columns when it does: a DST transition,
a move between hosts, or a `mysqldump` taken under one zone and restored under
another. Measured directly: a value written as `12:00:00` reads back as
`17:30:00` after a session-zone change.

The `mysql` connection now pins `'timezone' => '+00:00'`, matching
`config('app.timezone')`. MySQL then stores the instant the application meant and
returns the same one, and agrees with SQLite.

> **Set this before the first production write.** Changing it afterwards
> reinterprets data that is already stored.

Guarded by `the_connection_pins_a_timezone_so_a_timestamp_means_one_instant` and
`a_stored_moment_reads_back_as_the_moment_it_was_written`.

### 3.5 A test that was passing vacuously

Not a MySQL bug, but MySQL found it. `AiProvenanceTest` proved five columns were
immutable by writing a fixed literal `1` into each. For the two foreign keys that
value already *was* the stored one, so nothing was dirty, the save was a no-op no
guard had reason to refuse, and those two columns were never actually tested. The
replacement is now derived from the current value, and the assertion is that the
write is refused and the record unchanged — rather than that one particular
guard's wording appears.

## 4. Differences that remain, and why they are safe

### 4.1 Case sensitivity — MySQL is stricter, in every direction

`utf8mb4_unicode_ci` makes string equality and unique constraints
case-**insensitive**; SQLite's are case-**sensitive**. Measured on both engines:

| | SQLite | MySQL |
|---|---|---|
| `where('name', 'ALPHA METALWORKS')` | 0 rows | 1 row |
| `where('email', 'ADMIN@…')` | 0 rows | 1 row |
| insert `c-alpha` when `C-ALPHA` exists | accepted | **rejected** |

**No change made, deliberately.** MySQL is the stricter engine in each case, so
nothing becomes reachable in production that was not reachable in development.
Forcing a binary collation to match SQLite would be the worse outcome: it would
let `C-ALPHA` and `c-alpha` exist as two different businesses.

The one behavioural consequence to know about: **a customer or batch code that is
accepted in development may be rejected in production as a duplicate.** It fails
safe — it refuses, it does not merge.

Access-grant tokens are unaffected. The stored value is a lowercase SHA-256
digest and the caller supplies the *plaintext*, never the hash, so a
case-insensitive comparison on `token_hash` gives an attacker nothing. Asserted
by `an_access_grant_token_is_refused_whatever_its_case` rather than assumed.

### 4.2 `TIMESTAMP` range

MySQL's `TIMESTAMP` spans 1970-01-01 to **2038-01-19**; SQLite has no such limit.
Under `STRICT_TRANS_TABLES` a date beyond 2038 is rejected rather than truncated.

Nothing in BMP stores dates that far out — grants expire in days, programmes run
in months — so this is recorded as a known boundary, not a defect. If a future
feature needs a date past 2038, that column must be `dateTime()`, not
`timestamp()`.

### 4.3 Auto-increment counters under test

MySQL does not roll back an auto-increment counter when a transaction rolls back,
so ids climb across a `RefreshDatabase` run; SQLite's in-memory database restarts
from 1 for each test. **A test must never assume a specific id.** This is what
exposed §3.5.

## 5. What was audited and found already correct

- **No database `ENUM` columns.** Statuses are strings with PHP enum casts, which
  is what keeps them portable and alterable. Guarded by `no_column_is_a_database_enum`.
- **107 foreign keys**, every one valid, with deliberate mixed actions
  (70 `RESTRICT`, 20 `SET NULL`, 17 `CASCADE`). **Zero signed/unsigned type
  mismatches** — the classic MySQL foreign-key failure.
- **All 16 decimal columns** declare explicit precision and scale, so money is
  exact on MySQL. SQLite discards precision entirely, which is why the guard
  reads the migration rather than the built column.
- **No index approaches InnoDB's 3072-byte key limit**, and no `TEXT` column
  appears inside an index.
- **Both SQL `GROUP BY` queries are `ONLY_FULL_GROUP_BY`-safe.** Every other
  `groupBy` in the codebase is a Collection method operating in memory after
  `get()`, not SQL.
- **No SQLite-only SQL anywhere**: no `strftime`, `julianday`, `PRAGMA`,
  `AUTOINCREMENT`, `RANDOM()`, or `||` concatenation in a raw fragment.
- **Raw fragments are safe.** Eleven exist. Every one is ANSI-portable, and the
  only one that interpolates a value (`TargetVsActualCalculator`) allowlists the
  metric against `MmdEntry::METRICS` and passes it through the grammar's
  `wrap()`, so it is neither injectable nor mis-quoted on either engine.
- **Reserved words as column names are safe.** `status` and `key` appear as
  column names, but Laravel's grammar back-tick wraps every identifier it
  generates, so they are only ever emitted quoted. The exposure is raw fragments,
  which are guarded at source level.
- **No seeder or factory calls `env()`**, so seeding behaves the same before and
  after `config:cache`.

## 6. Known limitations

1. **Live MySQL testing is not part of CI.** It requires a server; the default
   suite deliberately does not. Run `phpunit.mysql.xml` before a release.
2. **MariaDB is untested.** A `mariadb` connection exists in
   `config/database.php` as framework default, but nothing here has been verified
   against it, and its reserved-word list differs from MySQL's (`ROWS`, for
   instance, is not reserved in MariaDB). Do not treat it as supported.
3. **The 2038 `TIMESTAMP` boundary** stands (§4.2).
4. **Collation is a deployment decision.** The tables are created with the
   collation in `config/database.php` (`utf8mb4_unicode_ci`). Creating the
   database itself with a different one does not change that, but a future
   `utf8mb4_0900_ai_ci` migration would change sort order for non-ASCII text.
