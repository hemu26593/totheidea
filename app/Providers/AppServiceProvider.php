<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordAuthenticationAudit;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        Event::subscribe(RecordAuthenticationAudit::class);

        $this->registerSuperAdminGate();
        $this->configurePasswordRules();
    }

    /**
     * Super Admin holds every permission (ADR-008).
     *
     * Granted by computation rather than stored grants, so a permission added
     * later is covered without a reseed.
     *
     * The guarded-ability check is the important part: returning true for
     * everything would also bypass UserPolicy's self-lockout safeguards, which
     * exist precisely to constrain Super Admins. Those abilities fall through
     * to the policy instead (ADR-012).
     */
    private function registerSuperAdminGate(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->isSuperAdmin()) {
                return null;
            }

            $guarded = (array) config('authorization.guarded_abilities', []);

            return in_array($ability, $guarded, true) ? null : true;
        });
    }

    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            // uncompromised() calls the Have I Been Pwned API. It is valuable in
            // production but must not be enabled where outbound HTTPS is
            // restricted: a failed lookup would block password resets entirely.
            return $this->app->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });
    }
}
