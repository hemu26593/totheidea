<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Terminates a user's live sessions.
 *
 * Checking is_active at login is not sufficient: without this, a deactivated
 * user stays authenticated until their session naturally expires — up to
 * SESSION_LIFETIME minutes after their access was revoked. EnsureUserIsActive
 * closes that window on the next request; this closes it immediately.
 *
 * Requires the database session driver, which this application uses. The
 * sessions table already indexes user_id, so the delete is cheap.
 */
class SessionInvalidator
{
    public function forUser(User $user): int
    {
        if (Config::get('session.driver') !== 'database') {
            // Other drivers (file, redis, array) cannot be indexed by user, so
            // there is nothing to purge. EnsureUserIsActive remains the
            // backstop on the next request.
            return 0;
        }

        return DB::table(Config::get('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
