<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Providers\AnthropicMessagesProvider;
use App\Domain\Ai\Providers\UnconfiguredAiProvider;
use App\Domain\Notifications\Contracts\ChannelDispatcher;
use App\Domain\Notifications\UnconfiguredChannelDispatcher;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Listeners\RecordAuthenticationAudit;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerNotificationChannel();
        $this->registerAiProvider();
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        Event::subscribe(RecordAuthenticationAudit::class);

        $this->configureTrustedProxies();

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
    /**
     * The delivery seam for notifications.
     *
     * Bound to a dispatcher that REFUSES. Every part of the notification
     * system above it is finished; no provider is wired, and WhatsApp and paid
     * messaging are out of scope for this phase. A no-op that reported success
     * would put false sends in notification_dispatches, which is the one table
     * that answers "did we actually contact this business?".
     *
     * Swapping this binding is the whole change needed to start delivering.
     */
    private function registerNotificationChannel(): void
    {
        $this->app->bind(ChannelDispatcher::class, UnconfiguredChannelDispatcher::class);
    }

    /**
     * The transport seam for AI.
     *
     * DEFAULTS TO REFUSING. With no AI_PROVIDER configured the application
     * binds a provider that throws, so an unconfigured environment fails
     * clearly at the point of use rather than producing text from nowhere.
     *
     * There is deliberately no 'fake' or 'echo' driver. Production code does
     * not fabricate AI responses; a test that needs one binds its own double,
     * where it cannot be reached by a stray environment variable.
     *
     * Swapping the driver is the whole change needed to start generating.
     */
    private function registerAiProvider(): void
    {
        $this->app->bind(AiProvider::class, function (): AiProvider {
            return match ((string) config('ai.provider', 'null')) {
                'null', '' => new UnconfiguredAiProvider,
                'anthropic' => $this->app->make(AnthropicMessagesProvider::class),
                default => throw AiProviderNotConfiguredException::unknownProvider(
                    (string) config('ai.provider'),
                ),
            };
        });
    }

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

    /**
     * Whose forwarded headers to believe.
     *
     * Configured here rather than in bootstrap/app.php because the config
     * repository is not bound when the middleware closure runs there, and
     * reading env() at that point would return the default the moment
     * `config:cache` had been used - which is exactly the production case this
     * setting exists for.
     *
     * TWO THINGS IN THIS SYSTEM DEPEND ON THE CALLER'S REAL ADDRESS.
     * AccessGrantRedeemer bounds token guessing per caller IP, so behind an
     * unread proxy every external request would share one address and a single
     * abuser could exhaust the budget for every participating business at once.
     * And a grant records last_used_ip, which answers nothing if it holds the
     * load balancer.
     *
     * Trusting nothing is the default and the safe direction: a forwarded
     * header is trivially forged, so it may be honoured only where a proxy is
     * known to sit in front and to overwrite it.
     */
    private function configureTrustedProxies(): void
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return;
        }

        TrustProxies::at($configured === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $configured)))));

        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
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
