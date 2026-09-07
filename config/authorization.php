<?php

declare(strict_types=1);

use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| Authorization source of truth (ADR-010)
|--------------------------------------------------------------------------
|
| Roles and permissions are defined here in code, not authored in the UI.
| The RolePermissionSeeder reconciles the database to this file, so permission
| names are version-controlled, code-reviewed, and diffable.
|
| Adding a permission here does NOT grant it to anyone. Every role is an
| explicit allowlist below — a role is never defined by subtraction, so a new
| permission cannot silently reach a role that was never reviewed for it.
|
| Super Admin is deliberately absent from the role map: it is granted through
| Gate::before() in AppServiceProvider rather than stored grants, so new
| permissions are covered automatically. See ADR-012 for the guarded abilities
| that Gate::before must NOT short-circuit.
|
*/

return [

    /*
    | Every permission in the system, grouped for presentation. The groups are
    | display metadata only; authorization never reads them.
    */
    'permissions' => [
        'User administration' => [
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.assign_role',
            'roles.manage',
        ],

        'Customer management' => [
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.archive',
            // No customers.delete: customer removal is archival (ADR-011).
        ],

        'BMP operations' => [
            'batches.view',
            'batches.create',
            'batches.edit',
            'batches.archive',

            'sessions.view',
            'sessions.create',
            'sessions.edit',
            'sessions.complete',
            'sessions.archive',

            'forms.view',
            'forms.create',
            'forms.edit',
            'forms.publish',
            'forms.archive',

            'assessments.view',
            'assessments.manage',

            'attendance.view',
            'attendance.manage',

            'assignments.view',
            'assignments.create',
            'assignments.edit',
            'assignments.complete',
            'assignments.archive',

            'day_plans.view',
            'day_plans.manage',

            'time_grid.manage',

            'mmd.view',
            'mmd.manage',
            'mmd.set_targets',

            'fund_plans.view',
            'fund_plans.manage',
            'fund_plans.approve',

            'action_items.view',
            'action_items.manage',

            'positions.manage',

            'hr_policies.manage',
            'hr_policies.publish',
        ],

        'External access' => [
            // Scoped, expiring links. Customers never receive an account.
            'access_grants.issue',
            'access_grants.revoke',
        ],

        'Documents and notes' => [
            'documents.manage',
            'notes.manage',
            // Gates the consultant's private notes. SOW section 6 requires
            // that participants never see them - a permission check, not a
            // template condition.
            'notes.view_internal',
        ],

        'Notifications' => [
            'notifications.view',
            'notifications.manage',
        ],

        'Analytics' => [
            'analytics.view',
        ],

        'Reports' => [
            'reports.view',
            'reports.export',
        ],

        'AI' => [
            'ai.forms.generate',
            'ai.forms.approve',
            'ai.analysis.generate',
            'ai.analysis.view',
        ],

        'System' => [
            'settings.manage',
            'audit.view',
        ],
    ],

    /*
    | Role -> permission allowlists.
    |
    | Super Admin is intentionally not listed. It resolves through Gate::before.
    */
    'roles' => [

        UserRole::Admin->value => [
            // User administration — no users.delete, no roles.manage.
            'users.view',
            'users.create',
            'users.edit',
            'users.assign_role',

            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.archive',

            'batches.view',
            'batches.create',
            'batches.edit',
            'batches.archive',

            'sessions.view',
            'sessions.create',
            'sessions.edit',
            'sessions.complete',
            'sessions.archive',

            'forms.view',
            'forms.create',
            'forms.edit',
            'forms.publish',
            'forms.archive',

            'assessments.view',
            'assessments.manage',

            'attendance.view',
            'attendance.manage',

            'assignments.view',
            'assignments.create',
            'assignments.edit',
            'assignments.complete',
            'assignments.archive',

            'day_plans.view',
            'day_plans.manage',

            'time_grid.manage',

            'mmd.view',
            'mmd.manage',
            'mmd.set_targets',

            'fund_plans.view',
            'fund_plans.manage',
            'fund_plans.approve',

            'action_items.view',
            'action_items.manage',

            'positions.manage',

            'hr_policies.manage',
            'hr_policies.publish',

            'access_grants.issue',
            'access_grants.revoke',

            'documents.manage',
            'notes.manage',
            'notes.view_internal',

            'notifications.view',
            'notifications.manage',

            'analytics.view',

            'reports.view',
            'reports.export',

            'ai.forms.generate',
            'ai.forms.approve',
            'ai.analysis.generate',
            'ai.analysis.view',

            // No settings.manage, no audit.view: the audit log records Admin
            // actions, so Admin does not control the record of its own behaviour.
        ],

        UserRole::Staff->value => [
            'customers.view',
            'customers.edit',

            'batches.view',

            'sessions.view',
            'sessions.create',
            'sessions.edit',
            'sessions.complete',
            // No sessions.archive.

            'forms.view',
            // No form template authoring.

            'assessments.view',
            'assessments.manage',

            'attendance.view',
            'attendance.manage',

            'assignments.view',
            'assignments.create',
            'assignments.edit',
            'assignments.complete',
            // No assignments.archive.

            'day_plans.view',
            'day_plans.manage',

            'time_grid.manage',

            'mmd.view',
            'mmd.manage',
            // No mmd.set_targets: setting a target is an approval-shaped act
            // and stays with Admin in V1.

            'fund_plans.view',
            'fund_plans.manage',
            // No fund_plans.approve.

            'action_items.view',
            'action_items.manage',

            'positions.manage',

            'hr_policies.manage',
            // No hr_policies.publish.

            'access_grants.issue',
            'access_grants.revoke',

            'documents.manage',
            'notes.manage',
            'notes.view_internal',

            'notifications.view',
            // No notifications.manage: retry and suppression change what a
            // customer receives, so they stay with Admin.

            'analytics.view',
            'reports.view',
            // No reports.export: export is a data-exfiltration boundary.

            'ai.analysis.view',
            // No AI generation or approval.
        ],
    ],

    /*
    | Abilities that Gate::before() must NOT auto-grant to Super Admin.
    |
    | These carry self-protection semantics (you cannot delete yourself, you
    | cannot remove the last Super Admin). If Gate::before returned true for
    | them, the safeguards would not apply to the only role able to trigger
    | them. These fall through to UserPolicy instead. See ADR-012.
    */
    'guarded_abilities' => [
        'delete',
        'deactivate',
        'assignRole',
        // AI generation approval. ADR-014 requires that a generator cannot
        // approve its own output; that check lives in the policy, so
        // Gate::before must not short-circuit it for a Super Admin.
        'approve',
    ],
];
