<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Enums\AuditAction;
use App\Http\Responses\NonEnumeratingPasswordResetLinkResponse;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A failed reset-link request must be indistinguishable from a
        // successful one, or the endpoint becomes an account-enumeration
        // oracle. Laravel's default response names the missing account.
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            NonEnumeratingPasswordResetLinkResponse::class,
        );
    }

    public function boot(): void
    {
        Fortify::viewPrefix('auth.');

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        $this->registerAuthenticationCallback();
        $this->registerRateLimiters();
    }

    /**
     * Credential verification, extended with the account-active check.
     *
     * A deactivated user must not be able to authenticate at all — this is the
     * login-time half of the protection; EnsureUserIsActive is the per-request
     * half for sessions that already exist.
     */
    private function registerAuthenticationCallback(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', $request->email)->first();
            $audit = app(AuditLogger::class);

            if ($user === null || ! Hash::check($request->password, $user->password)) {
                // Deliberately identical outcome whether the account exists or
                // the password is wrong — no account enumeration.
                $audit->log(AuditAction::LoginFailed, null, null, ['email' => $request->email]);

                return null;
            }

            if (! $user->is_active) {
                $audit->log(AuditAction::LoginBlockedInactive, $user, null, null, $user);

                return null;
            }

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            $audit->log(AuditAction::Login, $user, null, null, $user);

            return $user;
        });
    }

    /**
     * Throttle on email + IP together.
     *
     * IP alone locks out a whole office behind one NAT; email alone lets anyone
     * lock out a colleague whose address they know. The pair avoids both.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(
                Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }
}
