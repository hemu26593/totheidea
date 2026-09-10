<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Public registration must never exist (ADR-007).
 *
 * This is a regression test against a future Fortify upgrade or a careless
 * config edit re-enabling the feature.
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_registration_feature_is_not_enabled(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
    }

    #[Test]
    public function no_registration_route_is_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->all();

        $this->assertNotContains('register', $names);
        $this->assertNotContains('register.store', $names);
    }

    #[Test]
    public function the_registration_endpoints_are_unreachable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'a-strong-password-9',
            'password_confirmation' => 'a-strong-password-9',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }
}
