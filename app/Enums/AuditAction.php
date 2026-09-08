<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Auditable actions (ADR-013).
 *
 * The authentication and RBAC cases came from Step 2. The BMP domain cases
 * below are the set Step 3B section 6.1 requires; they are declared here in
 * Phase 0 so that every later phase writes through one audit vocabulary
 * rather than growing a second audit system.
 *
 * The audit_logs schema extension that accompanies them — source and
 * access_grant_id — belongs to Phase 1, because its foreign key needs the
 * access_grants table.
 */
enum AuditAction: string
{
    // Authentication
    case Login = 'auth.login';
    case Logout = 'auth.logout';
    case LoginFailed = 'auth.login_failed';
    case LoginBlockedInactive = 'auth.login_blocked_inactive';
    case PasswordReset = 'auth.password_reset';

    // User administration
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';

    // Roles and permissions
    case RoleAssigned = 'user.role_assigned';
    case RoleRevoked = 'user.role_revoked';
    case RolePermissionsChanged = 'role.permissions_changed';

    // Customers and enrolments
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerArchived = 'customer.archived';
    case CustomerContactConsentChanged = 'customer_contact.consent_changed';
    case EnrollmentCreated = 'enrollment.created';
    case EnrollmentWithdrawn = 'enrollment.withdrawn';
    case EnrollmentCompleted = 'enrollment.completed';
    case TermsAccepted = 'terms.accepted';

    // External access
    case AccessGrantIssued = 'access_grant.issued';
    case AccessGrantUsed = 'access_grant.used';
    case AccessGrantRevoked = 'access_grant.revoked';
    // A refused redemption. The caller is told nothing about why; staff can
    // see it here. A security path with no record of its failures is a blind
    // spot, and this is the only unauthenticated write path in the system.
    case AccessGrantDenied = 'access_grant.denied';

    // Forms, submissions and scoring
    case FormVersionPublished = 'form_version.published';
    case SubmissionSubmitted = 'submission.submitted';
    case SubmissionAmended = 'submission.amended';

    // Sessions and assignments
    //
    // Step 3B requires an audit entry on curriculum change, on schedule
    // change, cancellation and completion, and on assignment release and
    // closure. The four Step 2 cases below cover only marking and reviewing,
    // so the remaining events are named here rather than logged under a
    // borrowed action.
    case CurriculumChanged = 'curriculum.changed';
    case SessionScheduled = 'session.scheduled';
    case SessionRescheduled = 'session.rescheduled';
    case SessionCompleted = 'session.completed';
    case SessionCancelled = 'session.cancelled';
    case AttendanceMarked = 'attendance.marked';
    case AttendanceAmended = 'attendance.amended';
    case AssignmentReleased = 'assignment.released';
    case AssignmentClosed = 'assignment.closed';
    case AssignmentSubmitted = 'assignment.submitted';
    case AssignmentAccepted = 'assignment.accepted';
    case AssignmentReturned = 'assignment.returned';

    // Trackers
    case MmdEntryAmended = 'mmd_entry.amended';
    // Step 3B, table 31: "audit_logs on change". A revised target changes
    // every target-versus-actual conclusion drawn after it, so what it was
    // must survive.
    case MmdTargetSet = 'mmd_target.set';
    // Step 3B, table 29: "Amendable with audit - actuals arrive after
    // planning."
    case TimeGridAmended = 'time_grid_entry.amended';
    case FundPlanApproved = 'fund_plan.approved';

    // Business systems
    case HrPolicyPublished = 'hr_policy.published';
    case HrPolicyAcknowledged = 'hr_policy.acknowledged';

    // Outputs and AI
    case ReportGenerated = 'report.generated';
    case ReportExported = 'report.exported';
    case AiGenerated = 'ai.generated';
    case AiApproved = 'ai.approved';
    case AiRejected = 'ai.rejected';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::Logout => 'Signed out',
            self::LoginFailed => 'Failed sign-in',
            self::LoginBlockedInactive => 'Sign-in blocked (inactive)',
            self::PasswordReset => 'Password reset',
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::UserDeleted => 'User deleted',
            self::UserActivated => 'User activated',
            self::UserDeactivated => 'User deactivated',
            self::RoleAssigned => 'Role assigned',
            self::RoleRevoked => 'Role revoked',
            self::RolePermissionsChanged => 'Role permissions changed',
            self::CustomerCreated => 'Customer created',
            self::CustomerUpdated => 'Customer updated',
            self::CustomerArchived => 'Customer archived',
            self::CustomerContactConsentChanged => 'Contact consent changed',
            self::EnrollmentCreated => 'Enrolment created',
            self::EnrollmentWithdrawn => 'Enrolment withdrawn',
            self::EnrollmentCompleted => 'Enrolment completed',
            self::TermsAccepted => 'Terms accepted',
            self::AccessGrantIssued => 'Access grant issued',
            self::AccessGrantUsed => 'Access grant used',
            self::AccessGrantRevoked => 'Access grant revoked',
            self::AccessGrantDenied => 'Access grant refused',
            self::FormVersionPublished => 'Form version published',
            self::SubmissionSubmitted => 'Submission submitted',
            self::SubmissionAmended => 'Submission amended',
            self::CurriculumChanged => 'Curriculum changed',
            self::SessionScheduled => 'Session scheduled',
            self::SessionRescheduled => 'Session rescheduled',
            self::SessionCompleted => 'Session completed',
            self::SessionCancelled => 'Session cancelled',
            self::AttendanceMarked => 'Attendance marked',
            self::AttendanceAmended => 'Attendance amended',
            self::AssignmentReleased => 'Assignment released',
            self::AssignmentClosed => 'Assignment closed',
            self::AssignmentSubmitted => 'Assignment submitted',
            self::AssignmentAccepted => 'Assignment accepted',
            self::AssignmentReturned => 'Assignment returned',
            self::MmdEntryAmended => 'MMD entry amended',
            self::MmdTargetSet => 'MMD target set',
            self::TimeGridAmended => 'Time grid entry amended',
            self::FundPlanApproved => 'Fund plan approved',
            self::HrPolicyPublished => 'HR policy published',
            self::HrPolicyAcknowledged => 'HR policy acknowledged',
            self::ReportGenerated => 'Report generated',
            self::ReportExported => 'Report exported',
            self::AiGenerated => 'AI generation created',
            self::AiApproved => 'AI generation approved',
            self::AiRejected => 'AI generation rejected',
        };
    }

    /**
     * Security-significant actions, for filtering the audit view.
     */
    public function isSecurityEvent(): bool
    {
        return in_array($this, [
            self::RoleAssigned,
            self::RoleRevoked,
            self::RolePermissionsChanged,
            self::UserDeleted,
            self::UserDeactivated,
            self::LoginBlockedInactive,
            // External access is the one unauthenticated write path in the
            // system, so issuing, using and revoking a grant are security
            // events in the same sense as a role change.
            self::AccessGrantIssued,
            self::AccessGrantUsed,
            self::AccessGrantRevoked,
            self::AccessGrantDenied,
        ], true);
    }
}
