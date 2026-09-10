<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    /** Requirement 1 */
    #[Test]
    public function a_user_can_login_with_valid_credentials(): void
    {
        $user = $this->staff(['password' => Hash::make('correct-horse-battery-1')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    /** Requirement 2 */
    #[Test]
    public function invalid_credentials_are_rejected(): void
    {
        $user = $this->staff(['password' => Hash::make('correct-horse-battery-1')]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function a_nonexistent_account_fails_identically_to_a_wrong_password(): void
    {
        // No account enumeration: the response must not distinguish the cases.
        $response = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** Requirement 3 */
    #[Test]
    public function a_user_can_logout(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    /** Requirement 5 */
    #[Test]
    public function an_inactive_user_cannot_login(): void
    {
        $user = $this->staff([
            'password' => Hash::make('correct-horse-battery-1'),
            'is_active' => false,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function a_blocked_inactive_signin_is_audited(): void
    {
        $user = $this->staff([
            'password' => Hash::make('correct-horse-battery-1'),
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::LoginBlockedInactive->value,
            'auditable_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_successful_login_records_last_login_metadata(): void
    {
        $user = $this->staff(['password' => Hash::make('correct-horse-battery-1')]);

        $this->assertNull($user->last_login_at);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ]);

        $user->refresh();

        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $user = $this->staff(['password' => Hash::make('correct-horse-battery-1')]);

        // The limiter allows 5 attempts per minute, keyed on email + IP.
        foreach (range(1, 5) as $ignored) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // The sixth attempt is refused outright by the throttle rather than
        // reaching credential verification.
        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    #[Test]
    public function throttling_is_keyed_on_email_and_ip_together(): void
    {
        // Exhausting one account's attempts must not lock out a different
        // account from the same address — email-only or IP-only keying would.
        $victim = $this->staff(['password' => Hash::make('correct-horse-battery-1')]);
        $other = $this->staff(['password' => Hash::make('correct-horse-battery-2')]);

        foreach (range(1, 6) as $ignored) {
            $this->from('/login')->post('/login', [
                'email' => $victim->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->post('/login', [
            'email' => $other->email,
            'password' => 'correct-horse-battery-2',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($other);
    }

    #[Test]
    public function the_password_hash_is_never_serialised(): void
    {
        $user = $this->staff();

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    #[Test]
    public function guests_are_redirected_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function an_authenticated_user_reaches_the_dashboard(): void
    {
        $this->actingAs($this->staff())->get('/dashboard')->assertOk();
    }

    #[Test]
    public function login_and_logout_are_audited(): void
    {
        $user = $this->staff(['password' => Hash::make('correct-horse-battery-1')]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ]);

        $this->post('/logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Login->value,
            'actor_id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Logout->value,
            'actor_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_failed_login_audit_entry_never_stores_the_password(): void
    {
        $this->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'super-secret-value',
        ]);

        $entry = AuditLog::where('action', AuditAction::LoginFailed)->firstOrFail();

        $this->assertStringNotContainsString('super-secret-value', json_encode($entry->new_values));
    }
}
