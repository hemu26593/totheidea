<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks account status on every authenticated request.
 *
 * Checking is_active at login only is insufficient: Laravel does not re-read
 * the user's state per request, so a user deactivated mid-session would stay
 * fully authenticated until their session expired. This runs immediately after
 * the auth middleware on every authenticated route, Livewire's update endpoint
 * included.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'Your account has been deactivated.');
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your account has been deactivated.']);
        }

        return $next($request);
    }
}
