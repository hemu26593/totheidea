# Architecture Decision Records

This file records architecture decisions for the BMP Digital Platform, with the
context that motivated each one and its consequences.

Decisions are append-only. If a decision is later reversed, add a new ADR that
supersedes the old one and mark the old one as superseded — do not quietly edit
history.

**Status legend:** `Accepted` · `Superseded by ADR-NNN` · `Deprecated`

---

## ADR-001: Laravel 13 is the application framework

**Status:** Accepted
**Date:** 2026-08-31

### Decision

Laravel 13 is the application framework for the BMP Digital Platform.

### Context

The platform is a data-driven, form-heavy administrative application with
server-side authorisation requirements, background processing needs, and an
expected future AI integration. Laravel provides first-party support for all of
these — Eloquent, policies, queues, validation, migrations, and a mature testing
story — without assembling them from third-party parts.

Laravel 13 requires PHP ^8.3. The environment runs PHP 8.4.19, which satisfies
this with headroom.

### Consequences

- The framework's conventions govern structure; deviating from them needs a
  reason recorded here.
- The project inherits Laravel's release cadence and support window, and should
  track patch releases within the 13.x line.
- Laravel's built-in tooling (Pint, PHPUnit, Vite integration) is used in
  preference to alternatives, in line with ADR-002's bias against extra
  dependencies.

---

## ADR-002: The application is a modular monolith

**Status:** Accepted
**Date:** 2026-08-31

### Decision

The application is built as a modular monolith: a single deployable application,
internally organised into clear domain modules with explicit boundaries.

### Context

The BMP domain is cohesive — customers, batches, sessions, forms, assessments,
and reporting are tightly related and frequently queried together. Splitting
these across services would introduce network boundaries, distributed
transactions, and operational overhead for a team and workload that do not
require them.

At the same time, a structureless monolith degrades. Domain modules with
explicit boundaries keep the codebase navigable and preserve the option to
extract a service later if a genuine scaling or organisational need appears.

### Consequences

- Business logic belongs in service/domain classes, not controllers.
- Modules communicate through defined interfaces rather than reaching into each
  other's internals.
- Microservices are not to be introduced (see `CLAUDE.md`, Architecture rule 13).
  Reversing this requires a superseding ADR.
- Long-running work is handled with queued jobs inside the monolith, not by
  splitting out a service.

---

## ADR-003: Customers/businesses are NOT authentication users

**Status:** Accepted
**Date:** 2026-08-31

### Decision

Customers (the businesses enrolled in the BMP programme) are domain records. They
are **not** authentication principals. There is no customer login.

### Context

It is a common and costly modelling error to conflate "an entity the system holds
data about" with "an account that logs in". Doing so drags authentication
concerns — passwords, sessions, resets, lockouts, MFA — into what should be a
plain domain entity, and it blurs the authorisation model: it becomes unclear
whether a check is asking "who is acting?" or "whose data is this?".

In the BMP programme, internal staff operate the platform on behalf of
customers. Customers do not need and are not intended to have direct access.

### Consequences

- The `Customer` model must not use `Authenticatable`, and must not carry
  credentials.
- `Customer` and `User` are separate models with separate tables and separate
  lifecycles.
- "Which customer does this record belong to?" is an ownership question answered
  by a foreign key; "who is allowed to see it?" is an authorisation question
  answered by a policy. These stay distinct.
- If customer-facing access is ever required, it must be designed deliberately
  under a new ADR — not by adding a login to the `Customer` model.

---

## ADR-004: Only internal authorized users will eventually authenticate

**Status:** Accepted — deferral resolved by ADR-009 (2026-08-31)
**Date:** 2026-08-31

> **Update.** The core decision below still holds: internal users are the only
> authentication principals. The *deferral* it describes is finished. ADR-009
> selected session authentication with Laravel Fortify, ADR-008 and ADR-010
> settled roles and permissions, and the `users` table now carries `is_active`,
> `last_login_at` and `last_login_ip`. The two consequences about "before the
> starter kit decision is made" and the table being "otherwise untouched" are
> therefore historical, and are kept unedited per the append-only convention.

### Decision

The only authentication principals are authorised internal users. Authentication
is intentionally deferred; the starter kit will be selected and configured as a
separate, explicit step.

### Context

Authentication is a security-critical boundary and the choice of starter kit
(session-based, SPA, or API token) constrains the front-end architecture. Making
that choice as a side effect of scaffolding would lock in a decision that
deserves its own deliberation.

Deferring it is safe precisely because ADR-003 removes customer authentication
from scope — there is no second, harder auth surface waiting.

### Consequences

- No custom authentication, customer authentication, or BMP-specific auth logic
  is to be written before the starter kit decision is made.
- The default `users` table from Laravel's baseline migrations exists but is
  otherwise untouched.
- Authorisation is enforced server-side via policies, independent of whichever
  authentication mechanism is chosen (see `CLAUDE.md`, Architecture rules 5 and 8).
- Internal user roles/permissions will be designed alongside the starter kit
  decision.

---

## ADR-005: SQLite is used for the current development environment

**Status:** Accepted
**Date:** 2026-08-31

### Decision

The development environment uses SQLite, with the database file at
`database/database.sqlite`. The test suite uses in-memory SQLite.

### Context

No MySQL server is available in the current development environment: no `mysql`
or `mysqld` binary is installed, no database process is running, and nothing is
listening on port 3306. SQLite requires no server, is supported first-class by
Laravel 13 (it is the skeleton's default), and the required PHP extensions
(`pdo_sqlite`, `sqlite3`) are present.

This unblocks foundation work without provisioning infrastructure prematurely.

### Consequences

- `DB_DATABASE` is left unset so Laravel resolves the absolute path via
  `database_path()`, which is independent of the working directory.
- `phpunit.xml` pins tests to `:memory:`, so tests never touch development data.
- SQLite and MySQL differ in type affinity, strictness, constraint enforcement,
  and concurrency. Migrations and queries must be written portably, and
  SQLite-specific behaviour must not be relied upon.
- This is a development-environment decision only. It does not determine the
  production database — see ADR-006.

---

## ADR-006: MySQL is the intended production relational database

**Status:** Accepted
**Date:** 2026-08-31

### Decision

MySQL is the intended production relational database, unless changed during
architecture review.

### Context

MySQL is the expected production target for this platform. Recording it now
means development work can be kept portable from the start, rather than
discovering SQLite-specific assumptions at deployment time.

This decision is explicitly provisional and is a scheduled topic for
architecture review, where the production data store will be confirmed against
real requirements (expected data volume, reporting and analytics workload,
concurrency, hosting, and operational support).

### Consequences

- Development must avoid SQLite-only constructs so the MySQL switch is
  mechanical.
- `.env.example` carries commented MySQL settings as the documented path
  forward.
- Before production, the application must be run against a real MySQL instance,
  with the full migration set and test suite executed against it. Passing tests
  on SQLite is not evidence that the schema is MySQL-correct.
- If architecture review selects a different database, that decision supersedes
  this ADR and must be recorded as a new ADR.

---

## ADR-007: Internal users only; customers do not authenticate

**Status:** Accepted
**Date:** 2026-08-31
**Extends:** ADR-003

### Decision

Only internal staff authenticate. Public registration is not enabled and no
customer-facing authentication surface exists.

### Context

ADR-003 established that customers are domain records rather than authentication
principals. This ADR records how that is enforced in the implementation.

Fortify's features are opt-in: `Features::registration()` is simply absent from
`config/fortify.php`, so the `/register` route is never registered. There is no
controller to reach, nothing to remember to delete during an upgrade, and no
"disabled" flag that could be flipped by accident.

Accounts are created by authorized staff through user administration.

### Consequences

- `RegistrationDisabledTest` asserts the feature is off, no `register` route
  name exists, and both `/register` endpoints return 404. It is a regression
  test against a future Fortify upgrade re-enabling the feature.
- Any future customer-facing access must be designed under a new ADR, and must
  not be delivered by adding authentication to the `Customer` model.

---

## ADR-008: Three V1 roles — Super Admin, Admin, Staff

**Status:** Accepted
**Date:** 2026-08-31

### Decision

Exactly three roles exist: `super-admin`, `admin`, `staff`. No others are
created. Super Admin is granted through `Gate::before()` rather than stored
permission grants.

### Context

Roles are defined as explicit allowlists in `config/authorization.php`, never by
subtraction. "Full access minus four permissions" would silently grant every
permission added in future to that role, before anyone reviewed the decision.

Super Admin is the exception, and is deliberately computed rather than stored:
a permission added next year is covered automatically, with no reseed and no
second source of truth that can drift from the config.

### Consequences

- The seeder stores no permissions against `super-admin`; `Gate::before` is the
  only grant path. `PermissionMatrixTest` asserts a Super Admin holds a
  permission that does not exist in the config at all.
- `UserRole::rank()` (Staff 1, Admin 2, Super Admin 3) exists solely for the
  privilege-hierarchy comparisons in ADR-012. It is never persisted on the user.
- Adding a fourth role requires a superseding ADR. `PermissionMatrixTest`
  asserts the role set is exactly these three.

---

## ADR-009: Session-based authentication

**Status:** Accepted
**Date:** 2026-08-31

### Decision

Cookie/session authentication via Laravel's `web` guard with the database
session driver, using Laravel Fortify as the authentication backend. No API
tokens, no OAuth, no SPA token exchange. Email verification is not enabled.

### Context

This is a server-rendered internal application; session authentication is the
simplest correct choice. The **database** session driver specifically is what
makes forced logout and immediate revocation possible — see ADR-013's
deactivation requirements. A file or cookie driver could not be indexed by user.

Fortify was chosen over Breeze, Jetstream, and the official starter kits because
its features are opt-in flags rather than generated code to delete, and because
it provides the security-sensitive plumbing (login throttling, single-use reset
tokens, password confirmation) that would otherwise be hand-written.

Email verification is not enabled because accounts are created by an
administrator who already controls the address; a verification round-trip would
add a step without adding assurance.

### Consequences

- Login throttling is keyed on **email + IP together**. IP alone locks out a
  whole office behind one NAT; email alone lets anyone lock out a colleague
  whose address they know.
- "Remember me" is deliberately not offered: a long-lived authentication cookie
  is a poor trade on an internal administrative tool.
- A failed password-reset-link request returns the same response as a successful
  one (`NonEnumeratingPasswordResetLinkResponse`). Laravel's default reports
  "We can't find a user with that email address", which turns the public
  endpoint into an account-enumeration oracle.
- Two-factor authentication and passkeys are not enabled; Fortify ships the
  dependencies, so enabling either is a config change plus a migration.

---

## ADR-010: Role and permission based authorization

**Status:** Accepted
**Date:** 2026-08-31

### Decision

Authorization is permission-based, using `spatie/laravel-permission`. Roles and
permissions are defined in `config/authorization.php` and reconciled into the
database by an idempotent seeder. Application code never names a role.

### Context

The chain is `User → Role → Permission → Policy/Middleware → Action`. Code asks
about capabilities (`$user->can('customers.edit')`), never about identity
(`$user->hasRole('admin')`). This keeps the role set changeable without auditing
every call site.

Spatie earns its place because it integrates with Laravel's native `Gate`:
`can()`, `@can`, `authorize()` and `can:` middleware all work unchanged, so
call sites stay framework-standard and the package remains removable.

Defining permissions in config rather than a management UI means permission
names are version-controlled, code-reviewed, and diffable.

### Consequences

- Spatie's `role:` middleware is deliberately **unused** — using it would put
  role names back into application code.
- Wildcard permissions are not enabled. `users.*` would silently absorb every
  future `users` permission, reintroducing definition-by-subtraction.
- The seeder deletes permissions and roles absent from the config, so a rename
  cannot leave an orphaned grant behind.
- **Spatie caches the permission map.** Every code path that mutates roles or
  permissions must call `forgetCachedPermissions()`. Forgetting this produces
  authorization that is correct in tests and stale in production — the seeder
  and the test base class both reset it explicitly.
- `PermissionMatrixTest` asserts every (role × permission) pair against the
  config in both directions, so the specification and the implementation cannot
  drift.

---

## ADR-011: Customer deletion is replaced by archival

**Status:** Accepted
**Date:** 2026-08-31

### Decision

There is no `customers.delete` permission. Customer removal in normal operations
is `customers.archive` — a reversible soft-removal.

### Context

Permanently destroying a customer would cascade to its batches, sessions,
assessments and attendance records. Those are programme delivery evidence and
may carry retention obligations. An accidental hard delete is unrecoverable and
also destroys the audit trail's subject.

Archival covers every legitimate operational need: the customer disappears from
working views while the record and its history remain.

### Consequences

- `customers.delete` does not exist as a permission. `PermissionMatrixTest`
  asserts its absence, so reintroducing it requires a deliberate, reviewed
  change rather than an incidental one.
- Genuine erasure (a GDPR request, for example) is a separate, deliberate
  process to be designed under its own ADR — not a permission granted to a role
  for day-to-day use.
- Archive/restore semantics belong to the customer module and are not built yet.

---

## ADR-012: Role assignment is protected by privilege hierarchy

**Status:** Accepted
**Date:** 2026-08-31

### Decision

Two server-side invariants govern user administration:

1. **A user may only assign roles ranked at or below their own.** Admin may
   assign Admin and Staff, never Super Admin. Staff may assign nothing.
2. **A user may only act on users they rank at or above.** Admin cannot modify,
   demote, deactivate or delete a Super Admin.

Plus self-protection: no user may change their own role, deactivate their own
account, or delete their own account, at any rank.

### Context

This closes the escalation path in the original permission model. Admin held
`users.create` and `users.edit` but not `roles.manage` — which looked safe, but
`roles.manage` governs *defining* roles, and nothing governed *assigning* one to
a user. Since role assignment happens on the user form, an Admin could have
created a Super Admin, or granted it to themselves, bypassing every Admin
exclusion in two clicks.

`users.assign_role` was added as a distinct permission, and the rank invariant
enforces the boundary independently of it.

**Note on rank equality:** the general principle "never assign a role with
privilege equal to or higher than your own" and the explicit rule "Admin can
assign Admin or Staff" conflict at rank equality. The explicit per-role rule was
implemented (Admin may assign Admin), on the grounds that lateral assignment is
not escalation and an organisation needs more than one person able to create
Admins. Tightening to strictly-lower is a one-line change in
`UserService::canAssignRole()`.

### The `Gate::before` caveat

Super Admin is granted every permission through `Gate::before`. Returning `true`
unconditionally would also short-circuit the policies implementing the
safeguards above — the guards would not apply to the only role able to trigger
them.

`config('authorization.guarded_abilities')` lists `delete`, `deactivate` and
`assignRole`. `Gate::before` returns `null` for those, so they fall through to
`UserPolicy`. `SuperAdminSafeguardsTest` asserts this directly.

### Lockout protection

At least one **active** Super Admin must exist at all times. Deletion,
deactivation and demotion are the same event from the invariant's perspective
and pass through one guard, inside a transaction using `lockForUpdate()` so two
concurrent demotions cannot each observe one remaining Super Admin and both
proceed, leaving zero.

**`lockForUpdate()` is a no-op on SQLite**, which serialises writes globally.
The guard is therefore correct but not genuinely exercised in development.

**Validation against MySQL is deliberately deferred** until staging or
production infrastructure exists (decided 2026-08-31). No MySQL server is
available in the current environment, so the alternative would be either
provisioning one prematurely or writing an unverifiable claim. The deferral is
recorded here as an accepted, tracked risk rather than an oversight: the first
staging environment must run the full suite against MySQL and exercise
concurrent demotion specifically, before the guard can be relied on in anger.

Over HTTP this guard is largely subsumed by the self-action rule — only a Super
Admin outranks a Super Admin, so any *active* Super Admin actor implies the
target is not the last active one. Its real value is for console commands,
queued jobs and races, where no policy has run.

### Consequences

- Recovery from a violated invariant is `php artisan bmp:promote-super-admin`,
  which runs outside the HTTP authorization stack. **Shell access to the server
  is therefore equivalent to Super Admin** and must be governed accordingly.
  The promotion is audited.
- Provision at least two Super Admins. With exactly one, that account can never
  be demoted or deactivated by anyone.
- The role select in the UI is filtered for convenience only; the server
  re-checks the rank rule on save. Submitting a role absent from the list is
  rejected, not accepted.

---

## ADR-013: Audit logging is part of the authentication/RBAC foundation

**Status:** Accepted
**Date:** 2026-08-31

### Decision

A reusable, append-only `audit_logs` table and an `AuditLogger` service are part
of this phase rather than a later one. Authentication and user-administration
events are recorded through it.

### Context

The Super Admin safeguards and the privilege hierarchy are only as good as the
ability to see when they were exercised. Without an audit trail, a role
assignment or a deactivation leaves no evidence of who did it or when — and
Admin is deliberately denied `audit.view` precisely so the log is a check on
Admin behaviour, which only works if it exists.

The table is polymorphic and the enum is extensible so future domain modules
record through the same architecture rather than growing their own.

### Consequences

- Entries are immutable: `AuditLog` throws on update and delete. An audit trail
  that can be edited is not evidence.
- `AuditLogger` redacts password and token attributes unconditionally, whatever
  the caller passes — the log is read by more people than the records it
  describes.
- `actor_label` denormalises the actor's email so the trail stays readable after
  the actor's account is deleted (`actor_id` is nulled by the foreign key).
- Updates record only the attributes that actually changed, so the entry shows
  the change rather than a full copy of the record twice.
- Customer and BMP domain auditing is **not** built. Only the reusable
  foundation and the auth/RBAC events exist.

---

## ADR-014: AI-generated forms require independent approval

**Status:** Accepted
**Date:** 2026-08-31

### Decision

`ai.forms.generate` and `ai.forms.approve` are separate permissions, and a user
may not approve an AI form generation they personally generated. The future
AI form-generation record must carry `generated_by`.

No AI functionality is implemented in this phase — this records the
authorization architecture it must be built against.

### Context

Both permissions are currently granted to Admin and Super Admin. Without a
further rule, one person can generate a form and approve their own output,
making approval a workflow step rather than a control.

This matters more for AI output than for human work: model output is a
*proposal* that must be independently validated before it drives assessment
(CLAUDE.md, AI rules 2 and 3). An unreviewed AI-generated form could shape
scoring for an entire batch.

Recording it now is deliberate, because it is a **data-model** constraint: the
generation record needs `generated_by` from its first migration. Retrofitting
provenance onto existing rows later is far harder than including it.

### Consequences

- The future `AiFormGenerationPolicy::approve()` must return false when
  `$generation->generated_by === $user->id`, regardless of permissions held.
- Super Admin may approve where operationally appropriate, but the self-approval
  rule should apply to them too by default — `approve` should be added to
  `guarded_abilities` when that policy is built, so `Gate::before` does not
  bypass it. This is the same trap ADR-012 documents.
- An organisation with exactly one Admin cannot approve AI forms at all without
  a Super Admin. That is the intended consequence of separating the duties.
- `ai.analysis.generate` and `ai.analysis.view` carry no equivalent rule:
  analysis is advisory output, not an approval gate.

---

## ADR-015: External participants act through a capability, never an account

**Status:** Accepted
**Date:** 2026-09-09

### Decision

A participating business reaches the platform through exactly two
unauthenticated routes:

```
GET  /external/forms/{token}
POST /external/forms/{token}
```

The token in the path is the whole of the authority. It is presented again on
every request and re-validated from the database by `AccessGrantRedeemer`;
nothing about the caller survives between requests. `GET` calls `authorize()`
and consumes nothing, so a link may be opened, half-filled and reopened. `POST`
saves progress the same way, and only submitting reaches `redeem()` and spends
one of the grant's uses.

No customer, enrolment, form, version or submission identifier is accepted from
the request. Every one of them is resolved from the grant, so there is nothing
in a URL or a payload for a participant to substitute.

This does not weaken ADR-003, ADR-004 or ADR-007, and it must not be read as
softening them. There is still no Customer login, no customer password, no
customer guard, no customer session and no customer dashboard. A `Customer` is
still not `Authenticatable`. What exists is a scoped, expiring capability for
one action on one resource — closer to a signed URL than to an account.

### Context

The AccessGrant domain was built in Phase 5 and was complete: hashed tokens,
scope asserted at issue and again at redemption, atomic use counting, uniform
refusals. What it never had was an HTTP surface, so a business could not
actually fill in its own form; staff had to capture answers on their behalf.
Phase 11 UAT raised that as the one CRITICAL gap in the workflow.

The alternative — a customer portal with accounts — was rejected for the same
reasons ADR-003 records. An account is a standing identity that must be
provisioned, recovered, revoked and audited, and it grants access to whatever
that identity can reach. A capability grants one action on one resource and
then expires whether anyone remembers it or not.

### Consequences

- The default-deny route audit in `RouteAuthorizationTest` now carries one
  documented exclusion. Any *other* route that leaves the authenticated area
  still fails that test.
- Two rate limits apply, doing different jobs. Route-level `throttle` bounds
  request volume. The redeemer's own limiter bounds token *guessing*, and it
  counts refusals rather than uses — a participant re-presents their token on
  every saved answer, so counting successes would lock a business out of its
  own link partway through a long form.
- The external view is a plain HTML form rather than a Livewire component, so
  it works with no JavaScript on an unknown device. It shares the question-type
  mapping with the internal renderer through `AnswerValueMapper`; neither view
  writes an answer itself.

## Final permission matrix (V1)

42 permissions across 7 groups. Legend: **✔** granted · **·** not granted ·
**RANK** granted subject to the ADR-012 rank rule.

Super Admin resolves through `Gate::before` and holds no stored grants; the
column shows the effective result.

| Permission | Super Admin | Admin | Staff |
| --- | :---: | :---: | :---: |
| **User administration** | | | |
| `users.view` | ✔ | ✔ | · |
| `users.create` | ✔ | ✔ | · |
| `users.edit` | ✔ | ✔ | · |
| `users.delete` | ✔ | · | · |
| `users.assign_role` | ✔ | RANK | · |
| `roles.manage` | ✔ | · | · |
| **Customer management** | | | |
| `customers.view` | ✔ | ✔ | ✔ |
| `customers.create` | ✔ | ✔ | · |
| `customers.edit` | ✔ | ✔ | ✔ |
| `customers.archive` | ✔ | ✔ | · |
| **BMP operations** | | | |
| `batches.view` | ✔ | ✔ | ✔ |
| `batches.create` | ✔ | ✔ | · |
| `batches.edit` | ✔ | ✔ | · |
| `batches.archive` | ✔ | ✔ | · |
| `sessions.view` | ✔ | ✔ | ✔ |
| `sessions.create` | ✔ | ✔ | ✔ |
| `sessions.edit` | ✔ | ✔ | ✔ |
| `sessions.complete` | ✔ | ✔ | ✔ |
| `sessions.archive` | ✔ | ✔ | · |
| `forms.view` | ✔ | ✔ | ✔ |
| `forms.create` | ✔ | ✔ | · |
| `forms.edit` | ✔ | ✔ | · |
| `forms.publish` | ✔ | ✔ | · |
| `forms.archive` | ✔ | ✔ | · |
| `assessments.view` | ✔ | ✔ | ✔ |
| `assessments.manage` | ✔ | ✔ | ✔ |
| `attendance.view` | ✔ | ✔ | ✔ |
| `attendance.manage` | ✔ | ✔ | ✔ |
| `assignments.view` | ✔ | ✔ | ✔ |
| `assignments.create` | ✔ | ✔ | ✔ |
| `assignments.edit` | ✔ | ✔ | ✔ |
| `assignments.complete` | ✔ | ✔ | ✔ |
| `assignments.archive` | ✔ | ✔ | · |
| **Analytics** | | | |
| `analytics.view` | ✔ | ✔ | ✔ |
| **Reports** | | | |
| `reports.view` | ✔ | ✔ | ✔ |
| `reports.export` | ✔ | ✔ | · |
| **AI** | | | |
| `ai.forms.generate` | ✔ | ✔ | · |
| `ai.forms.approve` | ✔ | ✔ | · |
| `ai.analysis.generate` | ✔ | ✔ | · |
| `ai.analysis.view` | ✔ | ✔ | ✔ |
| **System** | | | |
| `settings.manage` | ✔ | · | · |
| `audit.view` | ✔ | · | · |

**Totals:** Super Admin 42 (computed) · Admin 38 stored · Staff 19 stored.

Permissions alone do not decide user administration. The rank rules and
self-protection guards in ADR-012 apply on top, which is why an Admin holding
`users.edit` still cannot edit a Super Admin.

---

## ADR-016: A confirmed customer enters the programme by batch assignment alone

**Status:** Accepted
**Date:** 2026-09-15

### Context

The client confirmed a change to the business rule: every customer entered into
this system is already a final, confirmed customer. The workflow the operator
performed until now was

```
Customer → (activate) → Enrol → Batch
```

which asked them to complete two further actions after creating a record that
was, by the client's account, already complete.

Two things are worth separating, because the brief that prompted this named
only the first. There was never a payment gate: nothing in the codebase blocked
any feature on payment, `enrollments.payment_due_date` is nullable and read only
by notification trigger 7, and an enrolment has always been `status = 'enrolled'`
from the moment it exists. What did exist was a `prospect` customer status with
a separate **Activate** action, and an enrolment step the operator had to
remember on a different screen.

### Decision

Programme entry is one action. `CustomerService::createInBatch()` creates the
customer and, when a batch is chosen, the enrolment, inside one transaction. A
customer is created with `status = 'active'`, because a confirmed customer was
never a prospect.

**Enrollment is retained, unchanged, as an internal record.** Twelve tables
carry a NOT NULL `enrollment_id` and resolve customer isolation through it
(ADR-004); it is also what keeps a repeat participant's two runs apart. It is no
longer a step anybody performs — the UI speaks of Programme and Batch, and the
word "enrol" has left the operator's vocabulary — but it remains the ownership
spine and nothing about its structure, its service or its policy has moved.

Assignment stays optional, so a customer may still be recorded before their
batch is known, and changing which batch an existing customer sits in remains on
the Programme screen, where it carries its own history.

### Consequences

`payment_due_date` and `PaymentDueTrigger` are untouched: they are a reminder,
not a prerequisite, and removing them would delete a live SOW requirement to fix
a gate that never existed. The field is absent from customer creation and stays
on the Programme screen, worded so it cannot be mistaken for a condition of
taking part.

The `prospect` status remains a valid value and stays in
`CustomerDirectory::STATUSES`, so rows created under the previous rule are
unchanged and still listable. The column default is untouched; status is set on
the insert instead, so nothing outside `CustomerService` changes behaviour.

No migration was required, and no existing data was rewritten.
