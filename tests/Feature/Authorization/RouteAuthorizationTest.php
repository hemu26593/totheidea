<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Requirements 7-11: role access, unauthorized routes, direct HTTP access.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    /** Requirement 7 */
    #[Test]
    public function super_admin_reaches_user_administration(): void
    {
        $this->actingAs($this->superAdmin())->get('/users')->assertOk();
        $this->actingAs($this->superAdmin())->get('/users/create')->assertOk();
    }

    /** Requirement 8 */
    #[Test]
    public function admin_reaches_user_administration(): void
    {
        $this->actingAs($this->admin())->get('/users')->assertOk();
        $this->actingAs($this->admin())->get('/users/create')->assertOk();
    }

    /** Requirement 9 */
    #[Test]
    public function staff_reaches_the_dashboard_but_not_user_administration(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/dashboard')->assertOk();
        $this->actingAs($staff)->get('/users')->assertForbidden();
        $this->actingAs($staff)->get('/users/create')->assertForbidden();
    }

    /** Requirement 10 */
    #[Test]
    public function guests_cannot_reach_any_application_route(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
        $this->get('/users/create')->assertRedirect('/login');
    }

    /** Requirement 11: direct HTTP access, bypassing the UI entirely. */
    #[Test]
    public function staff_receives_403_on_direct_requests_to_user_admin_urls(): void
    {
        $staff = $this->staff();
        $target = $this->staff();

        // No link to these is ever rendered for Staff; requesting them directly
        // must still be refused.
        $this->actingAs($staff)->get('/users')->assertForbidden();
        $this->actingAs($staff)->get('/users/'.$target->id.'/edit')->assertForbidden();
    }

    #[Test]
    public function a_user_with_no_role_reaches_nothing_but_the_dashboard(): void
    {
        $roleless = User::factory()->create();

        $this->actingAs($roleless)->get('/dashboard')->assertOk();
        $this->actingAs($roleless)->get('/users')->assertForbidden();
    }

    #[Test]
    public function every_non_public_route_requires_authentication(): void
    {
        // Default-deny audit: an application route added later without auth
        // middleware fails here rather than shipping silently.
        //
        // The exclusions are routes that are public by design. Livewire's own
        // endpoints are matched by pattern because Livewire 4 derives their
        // prefix per-application; they are protected by component-level
        // authorization instead (see LivewireAuthorizationTest).
        $publicByDesign = [
            '#^/$#',                       // redirect to /dashboard
            '#^up$#',                      // health check
            '#^login$#',
            '#^logout$#',
            '#^forgot-password$#',
            '#^reset-password#',
            '#^user/confirm(ed)?-password#',
            '#^user/confirmed-password-status$#',
            '#^user/password$#',
            '#^livewire[\w-]*/#',          // Livewire transport endpoints
            '#^storage/#',                 // filesystem disk routes

            // The external participant surface. Unauthenticated by design and
            // by necessity - a participating business has no account - but not
            // unprotected: every request presents a scoped AccessGrant token
            // that AccessGrantRedeemer re-validates from the database, and no
            // customer, enrolment, form or submission id is accepted from the
            // request. Its authorization is asserted over HTTP in
            // tests/Feature/External/ExternalFormAccessTest.php.
            '#^external/forms/#',
        ];

        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route): bool => in_array('auth', $route->gatherMiddleware(), true))
            ->reject(function ($route) use ($publicByDesign): bool {
                foreach ($publicByDesign as $pattern) {
                    if (preg_match($pattern, $route->uri()) === 1) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unprotected, 'These routes are not behind auth middleware.');
    }

    // The Livewire transport endpoint's protection is asserted in
    // LivewireAuthorizationTest, which checks the web middleware group and the
    // real HTTP behaviour rather than the route's unexpanded middleware list.
}
