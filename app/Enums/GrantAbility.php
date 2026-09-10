<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a scoped external access grant permits (Step 3B).
 *
 * A grant carries a capability, not an identity. The ability is always paired
 * with a subject, so a grant means "complete this form for this enrolment",
 * never "access customer X".
 */
enum GrantAbility: string
{
    case CompleteForm = 'complete_form';
    case EnterMmd = 'enter_mmd';
    case EnterDayPlan = 'enter_day_plan';
    case SubmitAssignment = 'submit_assignment';
    case AcceptTerms = 'accept_terms';
    case MarkAttendance = 'mark_attendance';
    case ViewReport = 'view_report';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
