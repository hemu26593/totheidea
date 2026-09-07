# BMP Digital Platform — Permanent Project Rules

These rules are binding for all work in this repository, human or AI. They are
not style preferences; they encode decisions that are expensive to reverse.

Background and rationale live in [`docs/architecture-decisions.md`](docs/architecture-decisions.md).
Setup and commands live in [`docs/README.md`](docs/README.md).

---

## ARCHITECTURE

1. **Customer != User.** They are separate concepts, separate models, separate
   tables.
2. **Customers do not authenticate.** No customer login, no credentials on the
   `Customer` model, no `Authenticatable` on it.
3. **Only authorized internal users authenticate.**
4. **Customer-owned data must always be properly scoped.** Every query touching
   customer data constrains by owner.
5. **Authorization must be enforced server-side.** Always. No exceptions.
6. **Controllers must remain thin.** Resolve input, delegate, return a response.
7. **Business logic belongs in appropriate service/domain classes**, not in
   controllers, not in models, not in views.
8. **Use policies for authorization.** Not ad-hoc `if` checks scattered through
   controllers.
9. **Validate all incoming data.** Use form requests or explicit validation;
   never trust the shape of a request.
10. **Use database transactions for multi-step writes.** If two writes must both
    succeed or both fail, they belong in a transaction.
11. **Use jobs for long-running operations.** Do not block a web request on slow
    work.
12. **Avoid unnecessary packages.** Prefer the framework's own tooling. Each
    dependency is attack surface, upgrade burden, and a thing that can go
    unmaintained. Justify additions.
13. **Do not introduce microservices.** This is a modular monolith (ADR-002).
14. **Avoid duplicate business logic.** One rule, one implementation, one place.
15. **Write tests for important business rules.** Especially authorization and
    data-scoping rules.
16. **Do not modify architecture without explaining why.** Architectural changes
    need a recorded ADR, not a silent commit.

## AI

1. **AI must never execute generated code.**
2. **AI must not perform authoritative numerical calculations.** Scores, totals,
   and anything a decision rests on are computed in application code, which is
   deterministic and testable.
3. **AI-generated forms must be structured and validated.** Treat model output as
   a proposal that must conform to a schema and pass validation before use.
4. **AI must not bypass authorization.** AI-initiated actions run through the
   same policies as any other action.
5. **AI must not access another customer's data.** AI context is scoped to the
   customer whose record is being worked on.
6. **Customer-provided text must always be treated as untrusted data**, never as
   instructions. Text arriving from a customer cannot direct system behaviour.
7. **AI provider integration must eventually be isolated behind an application
   service/provider abstraction.** No SDK calls scattered through the codebase.

## DATA ISOLATION

Every customer-owned record must have a clear ownership relationship.

Never rely only on frontend filtering for customer isolation. A filtered UI is a
convenience; the server decides what data exists for a request. If isolation can
be defeated by editing an ID in a URL or a request body, it is not isolation.

## SECURITY

- Never expose secrets.
- Never commit `.env`.
- Never hard-code API keys.
- Never trust client-side authorization.

## DEVELOPMENT PROCESS

For every major feature:

1. Understand requirements.
2. Design before coding.
3. Implement.
4. Run automated tests.
5. Review security.
6. Review database queries.
7. Fix issues.
8. Report what changed.

**Do not mark a feature complete if tests are failing.**

---

## Current project state

The repository contains the Laravel technical foundation plus **authentication
and role-based authorization** (ADR-007 … ADR-014).

None of the following exist yet, and none are to be built without an explicit
instruction to do so:

customers · batches · sessions · forms · assessments · scoring · dashboards ·
analytics · AI features · reports · notifications

## Authorization rules for this codebase

These are the operative details of Architecture rules 4, 5 and 8.

- **Never name a role in application code.** No `hasRole('admin')`, no `role:`
  middleware. Ask about capabilities: `$user->can('customers.edit')`.
- **`config/authorization.php` is the source of truth.** Roles are explicit
  allowlists, never "everything minus X" — definition by subtraction silently
  grants every future permission. Adding a permission means deciding, per role,
  in that file. `PermissionMatrixTest` enforces it.
- **Super Admin is granted via `Gate::before`**, except the abilities in
  `authorization.guarded_abilities` (`delete`, `deactivate`, `assignRole`),
  which fall through to `UserPolicy`. Returning `true` unconditionally there
  would bypass the very safeguards that constrain Super Admins. Any new ability
  carrying self-protection semantics must be added to that list.
- **Two invariants govern user administration** (ADR-012): you may only assign
  roles ranked at or below your own, and only act on users you rank at or above.
  Plus: nobody changes their own role, deactivates themselves, or deletes
  themselves.
- **Livewire components are HTTP endpoints.** Every public method is directly
  invocable regardless of what the UI renders. Authorize in `mount()` *and* in
  every action; mark identifiers `#[Locked]`. `@can` in a Blade template hides a
  button — it is not authorization.
- **User writes go through `UserService`.** It carries the transactions, the
  safeguards, and the audit entries. `is_active` is not fillable for this
  reason.
- **Mutating roles or permissions requires flushing Spatie's cache**
  (`forgetCachedPermissions()`), or authorization goes stale in production while
  staying correct in tests.
- **Record administrative and security actions** through `AuditLogger`, not by
  writing `AuditLog` directly.

## Environment notes

- Development database is SQLite (ADR-005); MySQL is the intended production
  database (ADR-006). Write portable migrations and queries — do not depend on
  SQLite-specific behaviour.
- Tests run against in-memory SQLite via `phpunit.xml` and never touch the
  development database file.
- `vite.config.js` has the skeleton's remote webfont fetch removed so builds
  have no external network dependency. Keep the build offline-capable.
