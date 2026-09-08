<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionAttendance;
use App\Models\User;

/**
 * Authorization for the attendance register.
 *
 * Marking and amending are the same capability - attendance.manage - because
 * they are the same act performed at different times, and splitting them would
 * invite an "amend-only" role that could rewrite a completion figure without
 * ever being trusted to record one.
 */
class SessionAttendancePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('attendance.view');
    }

    public function view(User $actor, SessionAttendance $attendance): bool
    {
        return $actor->can('attendance.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('attendance.manage');
    }

    public function update(User $actor, SessionAttendance $attendance): bool
    {
        return $actor->can('attendance.manage');
    }

    public function amend(User $actor, SessionAttendance $attendance): bool
    {
        return $actor->can('attendance.manage');
    }

    /**
     * Amended with audit, never deleted. The model enforces the absolute form.
     */
    public function delete(User $actor, SessionAttendance $attendance): bool
    {
        return false;
    }
}
