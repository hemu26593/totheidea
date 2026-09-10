# BMP Digital Platform

## Project purpose

The BMP Digital Platform is the digital system that supports the delivery and
administration of the BMP programme for totheidea.

The platform is operated by internal staff on behalf of the customers
(businesses) enrolled in the programme. Customers are records the platform holds
data *about* — they are not accounts that log in. Only authorised internal users
authenticate. See `ADR-003` and `ADR-004` in
[architecture-decisions.md](architecture-decisions.md).

This repository currently contains the technical foundation plus authentication
and role-based authorization. No BMP domain functionality (customers, batches,
sessions, forms, assessments, reporting, AI) has been built yet.

## Technology stack

| Layer | Choice | Version |
| --- | --- | --- |
| Framework | Laravel | 13.29.0 |
| Language | PHP | 8.4.19 (framework requires ^8.3) |
| Dependency manager | Composer | 2.8.12 |
| JavaScript runtime | Node.js | 22.22.2 |
| Package manager | npm | 10.9.7 |
| Build tool | Vite | 8.x (via `laravel-vite-plugin`) |
| CSS | Tailwind CSS | 4.x |
| Auth backend | Laravel Fortify | 1.39 |
| Authorization | spatie/laravel-permission | 8.3 |
| UI components | Livewire | 4.4 |
| Tests | PHPUnit | 12.5.x |
| Formatting | Laravel Pint | 1.x |

The application is structured as a modular monolith (`ADR-002`).

## Development database

Development uses **SQLite**, because no MySQL server is available in the current
development environment (`ADR-005`).

The database file lives at `database/database.sqlite`. `DB_DATABASE` is
deliberately left unset in `.env`, so Laravel resolves the path via
`database_path('database.sqlite')` — an absolute path that works no matter which
directory a command is run from.

The test suite does **not** use this file. `phpunit.xml` pins tests to an
in-memory SQLite database (`DB_DATABASE=:memory:`), so running tests never reads
or mutates development data.

**MySQL is the intended production database** (`ADR-006`). Because SQLite and
MySQL differ in type affinity, constraint enforcement, and concurrency
behaviour, migrations and queries should be written to be portable, and the
switch should be validated against a real MySQL instance before production.

## First-time setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed          # roles, permissions, and the initial Super Admin
npm install
npm run build
```

Seeding is not optional: without `RolePermissionSeeder` no roles or permissions
exist, and without `SuperAdminSeeder` there is no account that can sign in. See
[First sign-in](#first-sign-in) for setting the initial credentials.

## How to run the application

```bash
php artisan serve
```

The application is then available at <http://127.0.0.1:8000>.

For active frontend work, run the Vite dev server alongside it so assets rebuild
on change:

```bash
npm run dev
```

Laravel 13 also ships a combined runner that starts the server, queue worker, log
tailer, and Vite together:

```bash
composer dev
```

## How to run tests

```bash
php artisan test
```

Or run PHPUnit directly for more control:

```bash
./vendor/bin/phpunit --testdox          # readable test names
./vendor/bin/phpunit --filter=SomeTest  # a single test
```

Tests must pass before a feature is considered complete. See the development
process rules in the root `CLAUDE.md`.

## How to build frontend assets

```bash
npm run build     # production build, writes to public/build/
npm run dev       # dev server with hot reload
```

Note: the stock Laravel skeleton downloads the *Instrument Sans* webfont from
`fonts.bunny.net` during `vite build`. That has been removed from
`vite.config.js` so builds have no external network dependency. The font stack
in `resources/css/app.css` still names Instrument Sans first and falls back to
system fonts; self-hosting the font files later requires no config change.

## Authentication and authorization

Internal staff sign in at `/login`. There is no public registration and no
customer login — see ADR-007. Accounts are created by authorized staff at
`/users`.

Three roles exist: Super Admin, Admin, Staff (ADR-008). Authorization is
permission-based; `config/authorization.php` is the source of truth and the
seeder reconciles the database to it. Never write `$user->hasRole('admin')` —
ask about capabilities instead:

```php
$user->can('customers.edit');
$this->authorize('update', $user);
```

The full permission matrix is in
[architecture-decisions.md](architecture-decisions.md).

### First sign-in

`php artisan db:seed` creates the initial Super Admin. Set the credentials
through the environment rather than editing code:

```bash
BMP_SUPER_ADMIN_EMAIL=you@example.com \
BMP_SUPER_ADMIN_PASSWORD='a-strong-password' \
php artisan db:seed --class=SuperAdminSeeder
```

If `BMP_SUPER_ADMIN_PASSWORD` is unset, a password is generated and printed
once. **Provision a second Super Admin**: with exactly one, that account can
never be demoted or deactivated, because the last-Super-Admin guard refuses.

### Break-glass recovery

If no usable Super Admin remains:

```bash
php artisan bmp:promote-super-admin someone@example.com
```

This runs outside the HTTP authorization stack by design, so **shell access to
the server is equivalent to Super Admin**. The promotion is audited.

## Code style

```bash
./vendor/bin/pint          # apply formatting
./vendor/bin/pint --test   # check without modifying
```

## The internal application

Everything below `/dashboard` is the internal staff application. There is **no
customer login** anywhere in it: a customer is a business, not an account, and
external participation happens through scoped, expiring access grants.

| Area | Route |
|---|---|
| Operations console | `/dashboard` |
| Customer directory | `/customers` |
| Customer workspace | `/customers/{customer}` and its tabs |
| Batches, sessions, assignments | `/batches`, `/sessions`, `/assignments` |
| Attendance register | `/attendance` |
| A form submission | `/forms/{submission}` |
| Reports | `/reports` |
| Notification dispatches | `/notifications` |
| AI queue, one generation, prompt library | `/ai/generations`, `/ai/generations/{id}`, `/ai/prompts` |
| Administration | `/admin/...`, `/users` |

### Where the UI's rules live

- **Reusable components** are anonymous Blade components in
  `resources/views/components/ui/`. Prefer one of those to new markup.
- **The sidebar** is `App\Support\Navigation`. It asks the same permission the
  route's `can:` middleware asks, so a visible link is never a link the actor
  is then refused. It is a convenience, never authorization.
- **Customer-scoped screens** use `App\Livewire\Concerns\AuthorizesCustomerWorkspace`:
  a `#[Locked]` customer id, re-read from the database on every request, plus
  `assertOwnedByWorkspace()` for any record id that arrives from the browser.
- **Domain refusals** surface through
  `App\Livewire\Concerns\ReportsDomainFailures` as form errors rather than a
  500 — except an isolation failure, which is a 404, because whether another
  customer's record exists is itself information.

### The rule every Livewire component follows

Authorize on `mount()` **and** in every action. A public method on a Livewire
component is an HTTP endpoint whatever the rendered page offers, so
`@can` in a template hides a button and authorizes nothing.

## Deploying to production

Server requirements, environment variables, the deploy sequence, queue worker and
scheduler configuration, storage layout, backups, rollback and a smoke test are
in [`production-runbook.md`](production-runbook.md).

Two things there are worth knowing before you read the rest of it: the
development database is SQLite and production is MySQL (ADR-005, ADR-006), and
`php artisan config:cache` stops Laravel reading `.env` — so it must run *after*
the environment file is complete, never before.

What differs between the two database engines, what was changed to make them
agree, and how to run the suite against a real MySQL server is in
[`mysql-compatibility.md`](mysql-compatibility.md).

## Repository conventions

- `CLAUDE.md` (repository root) holds the permanent architecture, AI, data
  isolation, security, and process rules. Read it before making changes.
- `docs/architecture-decisions.md` records architecture decisions. Add a new ADR
  rather than silently revising an existing one.
- Never commit `.env`. It is git-ignored; `.env.example` is the tracked
  template and must not contain real secrets.
