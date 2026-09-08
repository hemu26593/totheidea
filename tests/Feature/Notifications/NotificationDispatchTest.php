<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Contracts\ChannelDispatcher;
use App\Domain\Notifications\DedupeKeyBuilder;
use App\Domain\Notifications\DispatchResult;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\NotificationDispatchService;
use App\Domain\Notifications\UnconfiguredChannelDispatcher;
use App\Enums\NotificationChannel;
use App\Exceptions\ChannelNotConfiguredException;
use App\Jobs\DispatchNotificationJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\NotificationDispatch;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The dispatch machinery: deduplication, queueing, retry, failure and consent.
 *
 * The property everything here defends is that the SAME QUALIFYING EVENT does
 * not produce a second message merely because the scheduler ran again. That is
 * not a nicety - six of the nine triggers describe conditions that stay true,
 * so without it the system sends every morning until it is muted.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDispatchService $dispatches;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->dispatches = app(NotificationDispatchService::class);
    }

    private function candidate(
        ?CustomerContact $contact = null,
        string $period = '2026-09-08',
        string $trigger = 'session_upcoming',
    ): NotificationCandidate {
        $customer = $contact?->customer ?? Customer::factory()->create();
        $contact ??= CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'owner@example.test',
            'email_opt_in' => true,
        ]);
        $enrollment = Enrollment::factory()->create(['customer_id' => $customer->getKey()]);
        $session = SessionInstance::factory()->create(['batch_id' => $enrollment->batch_id]);

        return new NotificationCandidate(
            triggerKey: $trigger,
            recipient: $contact,
            customerId: (int) $customer->getKey(),
            enrollmentId: (int) $enrollment->getKey(),
            subject: $session,
            scheduledFor: CarbonImmutable::parse('2026-09-08'),
            period: $period,
        );
    }

    // --- Idempotency -------------------------------------------------------

    #[Test]
    public function the_same_candidate_is_queued_once_however_often_it_is_offered(): void
    {
        $candidate = $this->candidate();

        $first = $this->dispatches->queue($candidate);
        $second = $this->dispatches->queue($candidate);
        $third = $this->dispatches->queue($candidate);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A repeat offer must be recognised, not sent again.');
        $this->assertNull($third);
        $this->assertSame(1, NotificationDispatch::query()->count());
    }

    #[Test]
    public function duplicate_prevention_is_a_database_constraint_not_a_lookup(): void
    {
        // A read-then-write check leaves a window in which two scheduler runs,
        // or two workers, both see nothing and both insert.
        $unique = collect(Schema::getIndexes('notification_dispatches'))
            ->filter(fn (array $i): bool => $i['unique'] === true)
            ->map(fn (array $i): array => $i['columns'])
            ->values();

        $this->assertTrue($unique->contains(['dedupe_key']));
    }

    #[Test]
    public function two_recipients_of_the_same_event_each_get_their_own_message(): void
    {
        $customer = Customer::factory()->create();
        $one = CustomerContact::factory()->create(['customer_id' => $customer->getKey(), 'email' => 'a@example.test']);
        $two = CustomerContact::factory()->create(['customer_id' => $customer->getKey(), 'email' => 'b@example.test']);

        $this->assertNotNull($this->dispatches->queue($this->candidate($one)));
        $this->assertNotNull($this->dispatches->queue($this->candidate($two)));

        // A dedupe key without the recipient would let the first written
        // suppress everyone else on the same session.
        $this->assertSame(2, NotificationDispatch::query()->count());
    }

    #[Test]
    public function a_later_period_is_a_new_message(): void
    {
        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create(['customer_id' => $customer->getKey(), 'email' => 'a@example.test']);

        $this->dispatches->queue($this->candidate($contact, '2026-09-08'));
        $this->dispatches->queue($this->candidate($contact, '2026-09-09'));

        $this->assertSame(2, NotificationDispatch::query()->count());
    }

    #[Test]
    public function a_dedupe_key_never_exceeds_the_indexable_length(): void
    {
        $builder = app(DedupeKeyBuilder::class);
        $key = $builder->build($this->candidate(period: str_repeat('x', 400)));

        // MySQL's utf8mb4 index prefix limit is 191 characters. A key that
        // would overflow is replaced by a digest rather than truncated -
        // truncation could merge two different notifications.
        $this->assertLessThanOrEqual(190, strlen($key));
        $this->assertStringStartsWith('sha256:', $key);
    }

    // --- Consent -----------------------------------------------------------

    #[Test]
    public function a_send_withheld_for_consent_is_recorded_not_skipped(): void
    {
        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'quiet@example.test',
            'email_opt_in' => false,
        ]);

        $dispatch = $this->dispatches->queue($this->candidate($contact));

        $this->assertNotNull($dispatch);
        $this->assertTrue($dispatch->wasSuppressed());
        $this->assertSame('quiet@example.test', $dispatch->address_used);
    }

    #[Test]
    public function a_suppressed_dispatch_is_never_queued_for_delivery(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'quiet@example.test',
            'email_opt_in' => false,
        ]);

        $dispatch = $this->dispatches->queue($this->candidate($contact));
        $this->dispatches->enqueue($dispatch);

        Queue::assertNothingPushed();
    }

    // --- The address snapshot ----------------------------------------------

    #[Test]
    public function the_address_is_snapshotted_at_creation(): void
    {
        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'before@example.test',
        ]);

        $dispatch = $this->dispatches->queue($this->candidate($contact));

        $contact->forceFill(['email' => 'after@example.test'])->save();

        // The record must say where the message actually went.
        $this->assertSame('before@example.test', $dispatch->fresh()->address_used);
    }

    #[Test]
    public function a_contact_with_no_address_produces_no_dispatch(): void
    {
        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => null,
        ]);

        // No address to snapshot means there is no send to record. That is a
        // gap in the customer's record, not a suppression.
        $this->assertNull($this->dispatches->queue($this->candidate($contact)));
        $this->assertSame(0, NotificationDispatch::query()->count());
    }

    // --- The queue ---------------------------------------------------------

    #[Test]
    public function a_pending_dispatch_is_pushed_onto_the_queue(): void
    {
        Queue::fake();

        $dispatch = $this->dispatches->queue($this->candidate());
        $this->dispatches->enqueue($dispatch);

        Queue::assertPushed(
            DispatchNotificationJob::class,
            fn (DispatchNotificationJob $job): bool => $job->dispatchId === (int) $dispatch->getKey(),
        );
    }

    #[Test]
    public function the_job_carries_an_id_so_it_re_reads_state(): void
    {
        // A serialised model would carry a snapshot of a row that an earlier
        // attempt may already have settled, and the job would then send a
        // message that had already gone out.
        $dispatch = NotificationDispatch::factory()->sent()->create();

        app(DispatchNotificationJob::class, ['dispatchId' => (int) $dispatch->getKey()])
            ->handle(new UnconfiguredChannelDispatcher);

        // Already sent, so the job did nothing - not even an attempt.
        $this->assertSame(1, $dispatch->fresh()->attempts);
    }

    #[Test]
    public function a_successful_send_records_the_provider_reference(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        $this->app->bind(ChannelDispatcher::class, fn (): ChannelDispatcher => new class implements ChannelDispatcher
        {
            public function send(NotificationDispatch $dispatch): DispatchResult
            {
                return new DispatchResult('provider-abc-123');
            }
        });

        (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));

        $fresh = $dispatch->fresh();
        $this->assertTrue($fresh->wasSent());
        $this->assertSame('provider-abc-123', $fresh->provider_message_id);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(1, $fresh->attempts);
    }

    #[Test]
    public function running_the_job_twice_sends_once(): void
    {
        $dispatch = NotificationDispatch::factory()->create();
        $sends = 0;

        $dispatcher = new class($sends) implements ChannelDispatcher
        {
            public function __construct(public int &$sends) {}

            public function send(NotificationDispatch $dispatch): DispatchResult
            {
                $this->sends++;

                return new DispatchResult('m-'.$this->sends);
            }
        };

        (new DispatchNotificationJob((int) $dispatch->getKey()))->handle($dispatcher);
        (new DispatchNotificationJob((int) $dispatch->getKey()))->handle($dispatcher);

        $this->assertSame(1, $sends, 'A replayed queue message must not send twice.');
    }

    // --- Failure and retry -------------------------------------------------

    #[Test]
    public function no_provider_is_wired_and_that_is_recorded_as_a_failure(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        // The default binding refuses. A no-op that reported success would put
        // false sends in the one table that answers "did we contact them?".
        $this->assertInstanceOf(UnconfiguredChannelDispatcher::class, app(ChannelDispatcher::class));

        try {
            (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));
            $this->fail('Expected the unconfigured dispatcher to refuse.');
        } catch (ChannelNotConfiguredException) {
            // expected
        }

        $fresh = $dispatch->fresh();
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->last_attempted_at);
        $this->assertStringContainsString('No delivery driver is configured', (string) $fresh->error);
    }

    #[Test]
    public function a_failure_within_the_retry_budget_stays_pending(): void
    {
        config()->set('notifications.max_attempts', 3);
        $dispatch = NotificationDispatch::factory()->create();

        $this->attemptAndSwallow($dispatch);

        // Still retryable, so still pending: the error is recorded without
        // closing the record.
        $this->assertTrue($dispatch->fresh()->isPending());
        $this->assertSame(1, $dispatch->fresh()->attempts);
    }

    #[Test]
    public function a_dispatch_that_exhausts_its_retries_is_marked_failed(): void
    {
        config()->set('notifications.max_attempts', 2);
        $dispatch = NotificationDispatch::factory()->create();

        $this->attemptAndSwallow($dispatch);
        $this->attemptAndSwallow($dispatch);

        $fresh = $dispatch->fresh();
        $this->assertTrue($fresh->hasFailed());
        $this->assertSame(2, $fresh->attempts);
    }

    #[Test]
    public function the_queues_final_failure_hook_settles_the_row(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        (new DispatchNotificationJob((int) $dispatch->getKey()))
            ->failed(new RuntimeException('provider unreachable'));

        $fresh = $dispatch->fresh();
        $this->assertTrue($fresh->hasFailed());
        $this->assertSame('provider unreachable', $fresh->error);
    }

    #[Test]
    public function retries_use_a_backoff(): void
    {
        $job = new DispatchNotificationJob(1);

        $this->assertSame((array) config('notifications.backoff'), $job->backoff());
        $this->assertSame((int) config('notifications.max_attempts'), $job->tries());
    }

    // --- The record itself -------------------------------------------------

    #[Test]
    public function a_dispatch_is_never_deleted(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never deleted');

        $dispatch->delete();
    }

    #[Test]
    public function the_identity_of_a_dispatch_cannot_be_rewritten(): void
    {
        $dispatch = NotificationDispatch::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixed at creation');

        $dispatch->forceFill(['dedupe_key' => 'something-else'])->save();
    }

    #[Test]
    public function the_dispatch_log_is_not_duplicated_into_the_audit_trail(): void
    {
        $before = AuditLog::query()->count();

        $this->dispatches->queue($this->candidate());

        // This table IS the notification record. Writing audit_logs per
        // dispatch would be the second audit mechanism the architecture
        // forbids.
        $this->assertSame($before, AuditLog::query()->count());
    }

    #[Test]
    public function only_the_two_real_kinds_of_recipient_exist(): void
    {
        // Participants have no account, so there is no third kind - and no
        // notification path can invent one.
        $this->assertSame(['contact', 'user'], NotificationDispatch::RECIPIENT_TYPES);
        $this->assertSame(['pending', 'sent', 'failed', 'suppressed'], NotificationDispatch::STATUSES);
        $this->assertSame(['email', 'whatsapp'], NotificationChannel::values());
    }

    private function attemptAndSwallow(NotificationDispatch $dispatch): void
    {
        try {
            (new DispatchNotificationJob((int) $dispatch->getKey()))->handle(app(ChannelDispatcher::class));
        } catch (Throwable) {
            // The rethrow is what makes the queue retry; the row already
            // carries the attempt.
        }
    }
}
