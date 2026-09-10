<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Contracts\ChannelDispatcher;
use App\Domain\Notifications\MailChannelDispatcher;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\NotificationDispatchService;
use App\Domain\Notifications\UnconfiguredChannelDispatcher;
use App\Enums\NotificationChannel;
use App\Exceptions\ChannelNotConfiguredException;
use App\Jobs\DispatchNotificationJob;
use App\Mail\NotificationMessage;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\NotificationDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * The email delivery channel.
 *
 * Nothing here talks to a real SMTP server: Mail::fake() and a transport that
 * throws on demand cover both outcomes. What is asserted is the CONTRACT the
 * rest of the notification system depends on - a send that happened, a failure
 * that is recorded and retried, and a row that never claims more than the
 * transport actually did.
 */
class MailChannelDispatcherTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_container_resolves_the_email_channel(): void
    {
        $this->assertInstanceOf(MailChannelDispatcher::class, app(ChannelDispatcher::class));
    }

    #[Test]
    public function notifications_can_be_switched_off_without_pretending_to_send(): void
    {
        // 'none' is the honest off switch: it binds the dispatcher that
        // refuses, so every dispatch is recorded as failed with a reason.
        config(['notifications.channel' => 'none']);

        $this->assertInstanceOf(UnconfiguredChannelDispatcher::class, app(ChannelDispatcher::class));

        config(['notifications.channel' => 'mail']);

        $this->assertInstanceOf(MailChannelDispatcher::class, app(ChannelDispatcher::class));
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_dispatch_is_sent_through_the_mail_layer_to_its_snapshotted_address(): void
    {
        Mail::fake();

        $dispatch = NotificationDispatch::factory()->create([
            'address_used' => 'owner@alpha-metalworks.test',
        ]);

        $result = app(MailChannelDispatcher::class)->send($dispatch);

        Mail::assertSent(NotificationMessage::class, fn (NotificationMessage $mail): bool => $mail->hasTo('owner@alpha-metalworks.test'));
        Mail::assertSentCount(1);

        $this->assertNotSame('', $result->providerMessageId);
    }

    #[Test]
    public function the_address_sent_to_is_the_snapshot_not_a_fresh_lookup(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'original@alpha.test',
        ]);

        $dispatch = NotificationDispatch::factory()->create([
            'customer_id' => $customer->getKey(),
            'recipient_id' => $contact->getKey(),
            'address_used' => 'original@alpha.test',
        ]);

        // The contact is edited after the dispatch was created.
        $contact->forceFill(['email' => 'changed@alpha.test'])->save();

        app(MailChannelDispatcher::class)->send($dispatch->fresh());

        Mail::assertSent(NotificationMessage::class, function (NotificationMessage $mail): bool {
            return $mail->hasTo('original@alpha.test') && ! $mail->hasTo('changed@alpha.test');
        });
    }

    #[Test]
    public function a_successful_send_marks_the_dispatch_sent_and_records_the_message_id(): void
    {
        Mail::fake();

        $dispatch = NotificationDispatch::factory()->create();

        (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));

        $fresh = $dispatch->fresh();

        $this->assertSame(NotificationDispatch::STATUS_SENT, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNotNull($fresh->provider_message_id);
        $this->assertNull($fresh->error);
        $this->assertSame(1, (int) $fresh->attempts);
    }

    #[Test]
    public function nothing_is_marked_sent_before_the_transport_has_accepted_it(): void
    {
        // The transport throws. The row must NOT read sent, and must carry the
        // reason - the whole point of the dispatch log.
        $this->bindFailingTransport('Connection could not be established with host smtp.example.com');

        $dispatch = NotificationDispatch::factory()->create();

        try {
            (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));
            $this->fail('A transport failure must not be swallowed.');
        } catch (RuntimeException) {
            // Rethrown so the queue applies its retry and backoff.
        }

        $fresh = $dispatch->fresh();

        $this->assertNotSame(NotificationDispatch::STATUS_SENT, $fresh->status);
        $this->assertNull($fresh->sent_at);
        $this->assertNull($fresh->provider_message_id);
        $this->assertNotNull($fresh->error);
    }

    /*
    |--------------------------------------------------------------------------
    | Failure, retry and the recorded outcome
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_failure_stays_pending_while_attempts_remain_and_fails_once_they_run_out(): void
    {
        config(['notifications.max_attempts' => 3]);
        $this->bindFailingTransport('Mailbox unavailable');

        $dispatch = NotificationDispatch::factory()->create();
        $job = new DispatchNotificationJob((int) $dispatch->getKey());

        // Attempts 1 and 2: still pending, so the queue will retry.
        foreach ([1, 2] as $attempt) {
            try {
                $job->handle(app(ChannelDispatcher::class));
            } catch (RuntimeException) {
            }

            $fresh = $dispatch->fresh();
            $this->assertSame(NotificationDispatch::STATUS_PENDING, $fresh->status);
            $this->assertSame($attempt, (int) $fresh->attempts);
        }

        // The third exhausts them.
        try {
            $job->handle(app(ChannelDispatcher::class));
        } catch (RuntimeException) {
        }

        $this->assertSame(NotificationDispatch::STATUS_FAILED, $dispatch->fresh()->status);
        $this->assertSame(3, (int) $dispatch->fresh()->attempts);
    }

    #[Test]
    public function the_retry_policy_is_unchanged_by_the_channel(): void
    {
        $job = new DispatchNotificationJob(1);

        $this->assertSame((int) config('notifications.max_attempts'), $job->tries());
        $this->assertSame((array) config('notifications.backoff'), $job->backoff());
    }

    #[Test]
    public function a_dispatch_that_is_no_longer_pending_is_not_sent_again(): void
    {
        Mail::fake();

        $dispatch = NotificationDispatch::factory()->sent()->create();

        (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_channel_with_no_driver_is_refused_rather_than_reported_sent(): void
    {
        Mail::fake();

        $dispatch = NotificationDispatch::factory()->create(['channel' => NotificationChannel::Whatsapp]);

        $this->expectException(ChannelNotConfiguredException::class);

        try {
            app(MailChannelDispatcher::class)->send($dispatch);
        } finally {
            Mail::assertNothingSent();
        }
    }

    #[Test]
    public function a_dispatch_with_no_address_is_refused(): void
    {
        Mail::fake();

        // address_used is fixed at creation, so an empty one can only exist
        // from the start - which is exactly the case being guarded.
        $dispatch = NotificationDispatch::factory()->create(['address_used' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no address to send to');

        app(MailChannelDispatcher::class)->send($dispatch);
    }

    #[Test]
    public function a_mailer_that_delivers_nowhere_is_refused_in_production(): void
    {
        // The log mailer accepts everything and delivers nothing. Recording
        // that as sent would put a false send in the dispatch log.
        config(['mail.default' => 'log']);
        app()->detectEnvironment(fn (): string => 'production');

        $dispatch = NotificationDispatch::factory()->create();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('does not deliver anything');

            app(MailChannelDispatcher::class)->send($dispatch);
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Content, isolation and secrets
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_email_names_only_its_own_business_and_carries_no_business_data(): void
    {
        Mail::fake();

        $alpha = Customer::factory()->create(['name' => 'Alpha Metalworks', 'code' => 'C-ALPHA']);
        $beta = Customer::factory()->create(['name' => 'Beta Textiles', 'code' => 'C-BETA']);

        $dispatch = NotificationDispatch::factory()->create(['customer_id' => $alpha->getKey()]);

        app(MailChannelDispatcher::class)->send($dispatch);

        Mail::assertSent(NotificationMessage::class, function (NotificationMessage $mail) use ($beta): bool {
            $rendered = $mail->render();

            $this->assertStringContainsString('Alpha Metalworks', $rendered);
            $this->assertStringNotContainsString($beta->name, $rendered, 'An email must not name another business.');

            return true;
        });
    }

    #[Test]
    public function a_customer_contact_is_never_given_a_sign_in_link(): void
    {
        Mail::fake();

        // Participants have no account (ADR-003/007), so a sign-in link would
        // be an invitation to a door that does not open for them.
        $toContact = NotificationDispatch::factory()->create([
            'recipient_type' => NotificationDispatch::RECIPIENT_CONTACT,
        ]);

        app(MailChannelDispatcher::class)->send($toContact);

        Mail::assertSent(NotificationMessage::class, function (NotificationMessage $mail): bool {
            $this->assertStringNotContainsString(route('dashboard'), $mail->render());
            $this->assertStringNotContainsString(route('login'), $mail->render());

            return true;
        });
    }

    #[Test]
    public function an_internal_recipient_is_given_the_dashboard(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $dispatch = NotificationDispatch::factory()->create([
            'recipient_type' => NotificationDispatch::RECIPIENT_USER,
            'recipient_id' => $user->getKey(),
            'address_used' => $user->email,
        ]);

        app(MailChannelDispatcher::class)->send($dispatch);

        Mail::assertSent(NotificationMessage::class, fn (NotificationMessage $mail): bool => str_contains($mail->render(), route('dashboard')));
    }

    #[Test]
    public function an_smtp_password_never_reaches_the_recorded_error_or_the_log(): void
    {
        // Symfony attaches the raw SMTP conversation - which contains the
        // base64 AUTH exchange, and so the mailbox password - to a
        // TransportException. The dispatch's error is rendered on the
        // Notifications screen and the queue serialises failures into
        // failed_jobs, so that object must not travel any further.
        $password = 'sup3r-s3cret-mailbox-pw';
        $authLine = base64_encode("\0user\0".$password);

        $written = [];
        Log::listen(function ($message) use (&$written): void {
            $written[] = $message->message.' '.json_encode($message->context);
        });

        $exception = new TransportException('Expected response code 250 but got code "535".');
        $exception->appendDebug("AUTH PLAIN {$authLine}\r\n535 authentication failed\r\n");

        $this->bindFailingTransport($exception);

        $dispatch = NotificationDispatch::factory()->create();

        $thrown = null;

        try {
            (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);

        foreach ([$password, $authLine] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dispatch->fresh()->error);
            $this->assertStringNotContainsString($secret, $thrown->getMessage());
            $this->assertStringNotContainsString($secret, (string) $thrown);

            foreach ($written as $line) {
                $this->assertStringNotContainsString($secret, $line);
            }
        }

        // The exception that escapes carries no debug payload at all, so
        // nothing downstream can dig it out.
        $this->assertNotInstanceOf(TransportException::class, $thrown);
        $this->assertNull($thrown->getPrevious());
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_channel_does_not_change_deduplication(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create(['customer_id' => $customer->getKey()]);
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);

        $candidate = new NotificationCandidate(
            triggerKey: 'session_upcoming',
            recipient: $contact,
            customerId: (int) $customer->getKey(),
            enrollmentId: (int) $enrollment->getKey(),
            subject: $enrollment,
            scheduledFor: CarbonImmutable::now(),
            period: '2026-W37',
        );

        $service = app(NotificationDispatchService::class);

        $first = $service->queue($candidate);
        $second = $service->queue($candidate);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A repeat of the same candidate is already handled, not queued again.');
        $this->assertSame(1, NotificationDispatch::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Password reset is a separate path and stays that way
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function password_reset_mail_does_not_travel_through_the_bmp_channel(): void
    {
        // Password reset uses Laravel's own Notifiable/ResetPassword path, not
        // ChannelDispatcher. It must keep working even with BMP notifications
        // switched off entirely - otherwise a locked-out administrator would
        // have no way back in.
        config(['notifications.channel' => 'none']);
        Notification::fake();

        $user = User::factory()->create(['email' => 'ops@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
        );

        // And no BMP dispatch was involved in it.
        $this->assertSame(0, NotificationDispatch::query()->count());
    }

    #[Test]
    public function a_failing_bmp_transport_does_not_stop_a_password_reset(): void
    {
        $this->bindFailingTransport('SMTP refused the BMP notification');
        Notification::fake();

        $user = User::factory()->create(['email' => 'ops2@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
        );
    }

    /**
     * Bind a mailer whose transport always throws.
     */
    private function bindFailingTransport(string|TransportException $failure): void
    {
        $exception = is_string($failure) ? new TransportException($failure) : $failure;

        Mail::extend('always-failing', fn (array $config = []) => new class($exception) extends AbstractTransport
        {
            public function __construct(private readonly TransportException $exception)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw $this->exception;
            }

            public function __toString(): string
            {
                return 'always-failing://';
            }
        });

        config([
            'mail.default' => 'always-failing',
            'mail.mailers.always-failing' => ['transport' => 'always-failing'],
        ]);
    }
}
