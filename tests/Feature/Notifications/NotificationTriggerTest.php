<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Contracts\NotificationTrigger;
use App\Domain\Notifications\NotificationCandidate;
use App\Domain\Notifications\NotificationScheduler;
use App\Domain\Notifications\Triggers;
use App\Models\AssignmentInstance;
use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\FormSubmission;
use App\Models\NotificationDispatch;
use App\Models\SessionInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nine SOW section 7 triggers.
 *
 * Each one is tested on the question that actually matters for it: can it
 * identify the right records, and does it leave the wrong ones alone? Delivery
 * is somebody else's problem and is tested separately.
 *
 * Three triggers cannot run yet. They say so, and the scheduler reports them -
 * a deferred trigger must not be indistinguishable from a trigger that found
 * nobody.
 */
class NotificationTriggerTest extends TestCase
{
    use RefreshDatabase;

    private NotificationScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        $this->scheduler = app(NotificationScheduler::class);
        Queue::fake();
    }

    /**
     * A complete, structurally valid graph: a business with a contact, enrolled
     * in a batch that has a session.
     *
     * @return array{0: Customer, 1: CustomerContact, 2: Enrollment, 3: Batch}
     */
    private function enrolledBusiness(): array
    {
        $customer = Customer::factory()->create();
        $contact = CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'owner'.uniqid().'@example.test',
            'is_primary' => true,
            'email_opt_in' => true,
        ]);
        $batch = Batch::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        return [$customer, $contact, $enrollment, $batch];
    }

    private function trigger(string $class): NotificationTrigger
    {
        return app($class);
    }

    /**
     * @return array<int, NotificationCandidate>
     */
    private function candidates(string $class, string $asOf): array
    {
        return iterator_to_array(
            $this->trigger($class)->candidates(CarbonImmutable::parse($asOf)),
            false,
        );
    }

    // --- All nine are registered -------------------------------------------

    #[Test]
    public function exactly_the_nine_sow_triggers_are_registered(): void
    {
        $keys = array_map(
            fn (NotificationTrigger $t): string => $t->key(),
            $this->scheduler->triggers(),
        );

        $this->assertSame([
            'session_upcoming',
            'intake_incomplete',
            'assignment_due',
            'assignment_overdue',
            'daily_dashboard_missing',
            'dashboard_missed_three_days',
            'payment_due',
            'attendance_below_threshold',
            'weekly_batch_summary',
        ], $keys);
    }

    #[Test]
    public function the_triggers_are_code_not_rows(): void
    {
        // None is invented and none is user-configurable in v1, which is why
        // there is no triggers table to seed.
        $this->assertFalse(Schema::hasTable('notification_triggers'));
    }

    // --- 1. Session upcoming -----------------------------------------------

    #[Test]
    public function trigger_one_finds_sessions_three_days_out_and_on_the_day(): void
    {
        [, , $enrollment, $batch] = $this->enrolledBusiness();

        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-04']);
        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-01']);
        // Neither 3 days out nor today.
        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-20']);

        $found = $this->candidates(Triggers\SessionUpcomingTrigger::class, '2026-10-01');

        $this->assertCount(2, $found);
        foreach ($found as $candidate) {
            $this->assertSame((int) $enrollment->getKey(), $candidate->enrollmentId);
        }
    }

    #[Test]
    public function trigger_one_ignores_a_cancelled_session(): void
    {
        [, , , $batch] = $this->enrolledBusiness();

        SessionInstance::factory()->cancelled()->create([
            'batch_id' => $batch->getKey(),
            'planned_date' => '2026-10-01',
        ]);

        $this->assertCount(0, $this->candidates(Triggers\SessionUpcomingTrigger::class, '2026-10-01'));
    }

    #[Test]
    public function trigger_one_ignores_a_withdrawn_participant(): void
    {
        [, , $enrollment, $batch] = $this->enrolledBusiness();
        $enrollment->forceFill(['status' => 'withdrawn'])->save();

        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-01']);

        // Reminding someone who has left the programme about its deadlines is
        // the behaviour that gets a channel muted.
        $this->assertCount(0, $this->candidates(Triggers\SessionUpcomingTrigger::class, '2026-10-01'));
    }

    #[Test]
    public function trigger_one_reaches_only_its_own_business(): void
    {
        [$a, , , $batchA] = $this->enrolledBusiness();
        [$b] = $this->enrolledBusiness();

        SessionInstance::factory()->create(['batch_id' => $batchA->getKey(), 'planned_date' => '2026-10-01']);

        $found = $this->candidates(Triggers\SessionUpcomingTrigger::class, '2026-10-01');

        $customerIds = array_unique(array_map(fn ($c): int => $c->customerId, $found));
        $this->assertSame([(int) $a->getKey()], array_values($customerIds));
        $this->assertNotContains((int) $b->getKey(), $customerIds);
    }

    // --- 2. Intake incomplete ----------------------------------------------

    #[Test]
    public function trigger_two_finds_drafts_and_leaves_submitted_forms_alone(): void
    {
        [$customer, , $enrollment] = $this->enrolledBusiness();

        FormSubmission::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $customer->getKey(),
        ]);
        FormSubmission::factory()->submitted()->create([
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $customer->getKey(),
        ]);

        $found = $this->candidates(Triggers\IntakeIncompleteTrigger::class, '2026-10-01');

        $this->assertCount(1, $found);
        $this->assertSame((int) $enrollment->getKey(), $found[0]->enrollmentId);
    }

    // --- 3. Assignment due --------------------------------------------------

    #[Test]
    public function trigger_three_finds_released_work_two_days_out_and_on_the_day(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        $session = SessionInstance::factory()->held()->create(['batch_id' => $batch->getKey()]);

        AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
            'due_at' => '2026-10-03 17:00:00',
        ]);
        AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
            'due_at' => '2026-10-01 17:00:00',
        ]);

        $this->assertCount(2, $this->candidates(Triggers\AssignmentDueTrigger::class, '2026-10-01'));
    }

    #[Test]
    public function trigger_three_ignores_an_unreleased_assignment(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        $session = SessionInstance::factory()->held()->create(['batch_id' => $batch->getKey()]);

        AssignmentInstance::factory()->create([
            'session_instance_id' => $session->getKey(),
            'due_at' => '2026-10-01 17:00:00',
        ]);

        // A draft has not been given to anyone.
        $this->assertCount(0, $this->candidates(Triggers\AssignmentDueTrigger::class, '2026-10-01'));
    }

    #[Test]
    public function trigger_three_leaves_out_whoever_has_already_handed_it_in(): void
    {
        [$customer, , $enrollment, $batch] = $this->enrolledBusiness();
        $session = SessionInstance::factory()->held()->create(['batch_id' => $batch->getKey()]);
        $instance = AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
            'due_at' => '2026-10-01 17:00:00',
        ]);

        AssignmentSubmission::factory()->submitted()->create([
            'assignment_instance_id' => $instance->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'customer_id' => $customer->getKey(),
        ]);

        $this->assertCount(0, $this->candidates(Triggers\AssignmentDueTrigger::class, '2026-10-01'));
    }

    // --- 4. Assignment overdue ----------------------------------------------

    #[Test]
    public function trigger_four_fires_the_next_day_then_every_three(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        $session = SessionInstance::factory()->held()->create(['batch_id' => $batch->getKey()]);
        AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
            'due_at' => '2026-10-01 17:00:00',
        ]);

        // Day 1, 4 and 7 fire; 2, 3, 5 and 6 do not. Being overdue stays true
        // indefinitely, so a daily send is exactly what the cadence prevents.
        foreach ([1 => true, 2 => false, 3 => false, 4 => true, 5 => false, 6 => false, 7 => true] as $day => $expected) {
            $asOf = CarbonImmutable::parse('2026-10-01')->addDays($day)->toDateString();
            $found = $this->candidates(Triggers\AssignmentOverdueTrigger::class, $asOf);

            $this->assertSame($expected, $found !== [], "Day {$day} should ".($expected ? '' : 'not ').'fire.');
        }
    }

    #[Test]
    public function trigger_four_tells_the_consultant_as_well_as_the_participant(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        $consultant = $this->staff(['email' => 'consultant@example.test']);
        $session = SessionInstance::factory()->held()->create([
            'batch_id' => $batch->getKey(),
            'conducted_by' => $consultant->getKey(),
        ]);
        AssignmentInstance::factory()->released()->create([
            'session_instance_id' => $session->getKey(),
            'due_at' => '2026-10-01 17:00:00',
        ]);

        $found = $this->candidates(Triggers\AssignmentOverdueTrigger::class, '2026-10-02');

        $types = array_map(fn ($c): string => $c->recipientType(), $found);
        $this->assertContains(NotificationDispatch::RECIPIENT_CONTACT, $types);
        $this->assertContains(NotificationDispatch::RECIPIENT_USER, $types);
    }

    // --- 5 and 6. Blocked on Phase 6 ---------------------------------------

    #[Test]
    public function trigger_five_defers_on_the_missing_table_and_the_open_cadence(): void
    {
        $reason = $this->trigger(Triggers\DailyDashboardMissingTrigger::class)->unresolvedDependency();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('mmd_entries', $reason);
        $this->assertStringContainsString('[L6]', $reason);
    }

    #[Test]
    public function trigger_five_refuses_to_be_evaluated_rather_than_guessing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->candidates(Triggers\DailyDashboardMissingTrigger::class, '2026-10-01');
    }

    #[Test]
    public function trigger_six_defers_on_the_missing_table(): void
    {
        $reason = $this->trigger(Triggers\DashboardMissedThreeDaysTrigger::class)->unresolvedDependency();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('mmd_entries', $reason);
        $this->assertStringContainsString('Phase 6', $reason);
    }

    #[Test]
    public function the_three_day_window_itself_is_configured_because_the_sow_states_it(): void
    {
        // What is missing for trigger 6 is the data, not the rule.
        $this->assertSame(3, (int) config('notifications.timing.dashboard_missed_days'));
    }

    // --- 7. Payment due -----------------------------------------------------

    #[Test]
    public function trigger_seven_fires_on_the_date_then_every_second_day(): void
    {
        [, , $enrollment] = $this->enrolledBusiness();
        $enrollment->forceFill(['payment_due_date' => '2026-10-01'])->save();

        foreach ([0 => true, 1 => false, 2 => true, 3 => false, 4 => true] as $day => $expected) {
            $asOf = CarbonImmutable::parse('2026-10-01')->addDays($day)->toDateString();
            $found = $this->candidates(Triggers\PaymentDueTrigger::class, $asOf);

            $this->assertSame($expected, $found !== [], "Day {$day} should ".($expected ? '' : 'not ').'fire.');
        }
    }

    #[Test]
    public function trigger_seven_is_inert_when_no_payment_date_is_tracked(): void
    {
        // [S3] - whether the platform tracks payment dates at all is open. An
        // enrolment with no date simply never qualifies, so if the answer is
        // "no" the trigger is inert without a code change.
        [, , $enrollment] = $this->enrolledBusiness();
        $this->assertNull($enrollment->payment_due_date);

        $this->assertCount(0, $this->candidates(Triggers\PaymentDueTrigger::class, '2026-10-01'));
    }

    // --- 9. Weekly batch summary --------------------------------------------

    #[Test]
    public function trigger_nine_fires_on_monday_and_addresses_internal_users(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        $consultant = $this->staff(['email' => 'weekly@example.test']);
        SessionInstance::factory()->create([
            'batch_id' => $batch->getKey(),
            'conducted_by' => $consultant->getKey(),
        ]);

        // 2026-10-05 is a Monday.
        $found = $this->candidates(Triggers\WeeklyBatchSummaryTrigger::class, '2026-10-05');

        $this->assertCount(1, $found);
        $this->assertSame(NotificationDispatch::RECIPIENT_USER, $found[0]->recipientType());
        $this->assertSame($consultant->getKey(), $found[0]->recipientId());
        // A batch roll-up spans many businesses, so neither field applies.
        $this->assertNull($found[0]->customerId);
        $this->assertNull($found[0]->enrollmentId);
    }

    #[Test]
    public function trigger_nine_is_silent_on_every_other_day(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        SessionInstance::factory()->create([
            'batch_id' => $batch->getKey(),
            'conducted_by' => $this->staff()->getKey(),
        ]);

        // Tuesday.
        $this->assertCount(0, $this->candidates(Triggers\WeeklyBatchSummaryTrigger::class, '2026-10-06'));
    }

    #[Test]
    public function trigger_nine_produces_nothing_for_a_batch_nobody_is_recorded_as_running(): void
    {
        $this->enrolledBusiness();

        // There is no Consultant role and no owner column on batches.
        // Inventing either to address this reminder would be inventing an
        // organisational rule.
        $this->assertCount(0, $this->candidates(Triggers\WeeklyBatchSummaryTrigger::class, '2026-10-05'));
    }

    // --- The sweep ----------------------------------------------------------

    #[Test]
    public function a_sweep_queues_qualifying_work_and_reports_what_it_could_not_run(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-01']);

        $result = $this->scheduler->sweep(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, $result->totalCreated());
        $this->assertArrayHasKey('daily_dashboard_missing', $result->deferred);
        $this->assertArrayHasKey('dashboard_missed_three_days', $result->deferred);
        $this->assertArrayHasKey('attendance_below_threshold', $result->deferred);
    }

    #[Test]
    public function running_the_sweep_twice_queues_the_work_once(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-01']);

        $first = $this->scheduler->sweep(CarbonImmutable::parse('2026-10-01'));
        $second = $this->scheduler->sweep(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, $first->totalCreated());
        $this->assertSame(0, $second->totalCreated());
        $this->assertSame(1, $second->totalDuplicates());
        $this->assertSame(1, NotificationDispatch::query()->count());
    }

    #[Test]
    public function the_scheduled_command_runs_the_sweep_and_names_what_is_deferred(): void
    {
        $this->artisan('bmp:notifications:sweep', ['--as-of' => '2026-10-01T09:00:00Z'])
            ->expectsOutputToContain('Swept at')
            ->expectsOutputToContain('Deferred [daily_dashboard_missing]')
            ->assertSuccessful();
    }

    #[Test]
    public function a_sweep_never_addresses_a_business_that_did_not_qualify(): void
    {
        [$a, , , $batchA] = $this->enrolledBusiness();
        [$b] = $this->enrolledBusiness();

        SessionInstance::factory()->create(['batch_id' => $batchA->getKey(), 'planned_date' => '2026-10-01']);

        $this->scheduler->sweep(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, NotificationDispatch::query()->where('customer_id', $a->getKey())->count());
        $this->assertSame(0, NotificationDispatch::query()->where('customer_id', $b->getKey())->count());
    }

    #[Test]
    public function every_dispatch_a_sweep_creates_names_a_real_recipient(): void
    {
        [, , , $batch] = $this->enrolledBusiness();
        SessionInstance::factory()->create(['batch_id' => $batch->getKey(), 'planned_date' => '2026-10-01']);

        $this->scheduler->sweep(CarbonImmutable::parse('2026-10-01'));

        foreach (NotificationDispatch::query()->get() as $dispatch) {
            $this->assertContains($dispatch->recipient_type, NotificationDispatch::RECIPIENT_TYPES);
            $this->assertNotSame('', $dispatch->address_used);

            $exists = $dispatch->recipient_type === NotificationDispatch::RECIPIENT_USER
                ? User::query()->whereKey($dispatch->recipient_id)->exists()
                : CustomerContact::query()->whereKey($dispatch->recipient_id)->exists();

            $this->assertTrue($exists);
        }
    }
}
