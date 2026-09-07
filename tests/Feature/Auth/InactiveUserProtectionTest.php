<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use App\Services\SessionInvalidator;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Requirement 6: an inactive authenticated user loses access.
 *
 * Checking is_active at login is not enough — without the middleware, a user
 * deactivated mid-session stays authenticated until the session expires.
 */
class InactiveUserProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function an_already_authenticated_user_loses_access_when_deactivated(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Deactivated after the session already exists.
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function a_deactivated_user_is_logged_out_by_the_middleware(): void
    {
        $user = $this->staff();

        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');

        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_user_is_blocked_on_every_authenticated_route(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/users')->assertOk();

        $admin->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->get('/users')->assertRedirect('/login');
    }

    #[Test]
    public function a_json_request_from_a_deactivated_user_is_forbidden(): void
    {
        $user = $this->staff();

        $this->actingAs($user);
        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/dashboard')->assertForbidden();
    }

    #[Test]
    public function deactivating_a_user_deletes_their_database_sessions(): void
    {
        Config::set('session.driver', 'database');

        $target = $this->staff();
        $actor = $this->superAdmin();

        DB::table('sessions')->insert([
            'id' => 'session-under-test',
            'user_id' => $target->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode('payload'),
            'last_activity' => now()->getTimestamp(),
        ]);

        // A session belonging to someone else must survive.
        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $actor->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => base64_encode('payload'),
            'last_activity' => now()->getTimestamp(),
        ]);

        app(UserService::class)->deactivate($target, $actor);

        $this->assertDatabaseMissing('sessions', ['id' => 'session-under-test']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    #[Test]
    public function the_session_invalidator_is_a_no_op_for_non_database_drivers(): void
    {
        Config::set('session.driver', 'array');

        $this->assertSame(0, app(SessionInvalidator::class)->forUser($this->staff()));
    }

    #[Test]
    public function reactivating_a_user_restores_access(): void
    {
        $target = $this->staff(['is_active' => false]);
        $actor = $this->superAdmin();

        app(UserService::class)->activate($target, $actor);

        $this->assertTrue($target->fresh()->is_active);
        $this->actingAs($target->fresh())->get('/dashboard')->assertOk();
    }

    #[Test]
    public function the_active_middleware_is_applied_to_every_authenticated_route(): void
    {
        // A route added later must not be able to omit the check by accident.
        $unprotected = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('auth', $route->gatherMiddleware(), true))
            ->reject(fn ($route): bool => in_array(
                EnsureUserIsActive::class,
                $route->gatherMiddleware(),
                true,
            ) || in_array('active', $route->gatherMiddleware(), true))
            ->map(fn ($route): string => $route->uri())
            ->all();

        $this->assertSame([], array_values($unprotected));
    }
}
