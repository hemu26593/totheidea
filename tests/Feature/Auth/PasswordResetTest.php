<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Requirement 4: password reset flow. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
    }

    #[Test]
    public function the_reset_link_screen_is_reachable(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    #[Test]
    public function a_reset_link_is_emailed(): void
    {
        Notification::fake();

        $user = $this->staff();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function requesting_a_reset_for_an_unknown_address_does_not_reveal_it(): void
    {
        Notification::fake();

        // Same outcome as a known address: no account enumeration.
        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-strong-password-9',
            'password_confirmation' => 'a-new-strong-password-9',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('a-new-strong-password-9', $user->fresh()->password)
        );
    }

    #[Test]
    public function a_reset_token_cannot_be_reused(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-strong-password-9',
            'password_confirmation' => 'a-new-strong-password-9',
        ];

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();

        // Second use of the same token must fail — tokens are single-use.
        $this->from('/reset-password/'.$token)
            ->post('/reset-password', $payload)
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function a_weak_password_is_rejected(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = Password::createToken($user);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function a_completed_reset_is_audited(): void
    {
        Notification::fake();

        $user = $this->staff();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-strong-password-9',
            'password_confirmation' => 'a-new-strong-password-9',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PasswordReset->value,
            'auditable_id' => $user->id,
        ]);
    }
}
