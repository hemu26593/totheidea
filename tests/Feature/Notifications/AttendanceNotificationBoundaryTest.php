<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\NotificationScheduler;
use App\Domain\Notifications\Triggers\AttendanceBelowThresholdTrigger;
use App\Domain\Sessions\AttendanceCalculator;
use App\Domain\Sessions\Contracts\AttendanceWeighting;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Enrollment;
use App\Models\NotificationDispatch;
use App\Models\SessionAttendance;
use App\Models\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRIGGER 8 - attendance below 90%.
 *
 * [CLIENT DECISION - ATTENDANCE WEIGHTING]
 *
 * This file tests the INTEGRATION CONTRACT and nothing else. It proves:
 *
 *   - the trigger exists, is registered, and is wired to the calculator;
 *   - with no weighting bound it defers, and the scheduler says so;
 *   - the moment a weighting IS bound, the trigger runs unchanged.
 *
 * It does NOT decide how `late` and `excused` weigh. The stub used in the last
 * test is a WIRING PROBE, deliberately trivial and deliberately confined to
 * this file: it is never bound in application code, never in config, and is
 * not a proposal. Phase 4's five skipped weighting tests remain skipped,
 * because what they are waiting for is a decision from the client, not a
 * decision from here.
 */
#[Group('client-decision')]
class AttendanceNotificationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthorization();
        Queue::fake();
    }

    #[Test]
    public function the_trigger_exists_and_is_registered(): void
    {
        $keys = array_map(
            fn ($t): string => $t->key(),
            app(NotificationScheduler::class)->triggers(),
        );

        $this->assertContains('attendance_below_threshold', $keys);
    }

    #[Test]
    public function with_no_weighting_bound_the_trigger_defers_and_says_why(): void
    {
        $reason = app(AttendanceBelowThresholdTrigger::class)->unresolvedDependency();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('[CLIENT DECISION - ATTENDANCE WEIGHTING]', $reason);
        $this->assertStringContainsString('late', $reason);
        $this->assertStringContainsString('excused', $reason);
    }

    #[Test]
    public function the_sweep_reports_it_as_deferred_rather_than_finding_nobody(): void
    {
        $result = app(NotificationScheduler::class)->sweep(CarbonImmutable::parse('2026-10-01'));

        // Silence would look exactly like "nobody is below 90%", which is a
        // very different and much more dangerous statement.
        $this->assertArrayHasKey('attendance_below_threshold', $result->deferred);
        $this->assertStringContainsString(
            'ATTENDANCE WEIGHTING',
            $result->deferred['attendance_below_threshold'],
        );
    }

    #[Test]
    public function no_weighting_implementation_exists_in_application_code(): void
    {
        // Unchanged from Phase 4. Nothing in Phase 5 decided this.
        $this->assertFalse(app()->bound(AttendanceWeighting::class));

        $implementations = array_filter(
            get_declared_classes(),
            fn (string $class): bool => is_subclass_of($class, AttendanceWeighting::class)
                && ! str_starts_with($class, 'class@anonymous'),
        );

        $this->assertSame([], array_values($implementations));
    }

    #[Test]
    public function the_calculator_still_cannot_be_constructed_without_the_clients_answer(): void
    {
        $this->expectException(BindingResolutionException::class);
        app(AttendanceCalculator::class);
    }

    #[Test]
    public function the_threshold_is_unchanged(): void
    {
        $this->assertSame(0.90, AttendanceCalculator::COMPLETION_THRESHOLD);
    }

    #[Test]
    public function the_seam_works_the_moment_a_weighting_is_bound(): void
    {
        // ---------------------------------------------------------------
        // WIRING PROBE, NOT A PROPOSED RULE.
        //
        // This anonymous class answers the two open questions arbitrarily so
        // that the plumbing either side of them can be exercised. It exists
        // only inside this test method. It is not bound in AppServiceProvider,
        // does not appear in config, and must not be taken as a suggestion
        // about how `late` or `excused` should count.
        //
        // What is being proved is narrow and worth proving: that the trigger,
        // the calculator, the recipient resolution and the dispatch log are
        // all correctly connected, so that answering the client question is
        // the ONLY change needed to switch this trigger on.
        // ---------------------------------------------------------------
        $this->app->bind(AttendanceWeighting::class, fn (): AttendanceWeighting => new class implements AttendanceWeighting
        {
            public function attendanceCredit(string $status): float
            {
                return $status === SessionAttendance::STATUS_PRESENT ? 1.0 : 0.0;
            }

            public function countsTowardTotal(string $status): bool
            {
                return true;
            }
        });

        $customer = Customer::factory()->create();
        CustomerContact::factory()->create([
            'customer_id' => $customer->getKey(),
            'email' => 'owner@example.test',
            'is_primary' => true,
        ]);
        $batch = Batch::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'customer_id' => $customer->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

        // Two held sessions, both missed: no arithmetic can reach 90%.
        foreach (range(1, 2) as $ignored) {
            $session = SessionInstance::factory()->held()->create(['batch_id' => $batch->getKey()]);
            SessionAttendance::factory()->create([
                'session_instance_id' => $session->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'customer_id' => $customer->getKey(),
                'status' => SessionAttendance::STATUS_ABSENT,
            ]);
        }

        $trigger = app(AttendanceBelowThresholdTrigger::class);

        // With a weighting bound, the trigger stops deferring - with no change
        // to the trigger itself.
        $this->assertNull($trigger->unresolvedDependency());

        $result = app(NotificationScheduler::class)->sweep(CarbonImmutable::parse('2026-10-01'));

        $this->assertArrayNotHasKey('attendance_below_threshold', $result->deferred);
        $this->assertSame(
            1,
            NotificationDispatch::query()->where('trigger_key', 'attendance_below_threshold')->count(),
        );
    }

    #[Test]
    public function the_phase_four_weighting_tests_are_still_skipped(): void
    {
        // If these ever start passing, someone chose a rule. The whole point
        // of this phase's boundary is that nobody has.
        $source = file_get_contents(base_path('tests/Feature/Domain/AttendanceWeightingTest.php'));

        $this->assertSame(
            5,
            substr_count($source, 'markTestSkipped(self::DECISION)'),
            'Phase 4 left five weighting tests written and skipped. They stay that way until the '
            .'client answers.'
        );
    }
}
