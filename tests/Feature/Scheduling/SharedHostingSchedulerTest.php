<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Models\NotificationDispatch;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scheduler has to work on hosting that does not allow proc_open.
 *
 * WHY THIS FILE EXISTS. `Schedule::command()` launches the command in a
 * SEPARATE PROCESS, built with Symfony's Process, which refuses to start
 * without proc_open — and it does that on the foreground path too, not only
 * under runInBackground(). Shared hosting routinely lists proc_open in
 * disable_functions. There, `schedule:run` throws
 * "The Process class relies on proc_open" and NEITHER of this application's two
 * scheduled jobs ever runs: no notification is ever queued, and no unfinished
 * day-plan task is ever carried forward. Nothing surfaces it, because the
 * failure happens inside schedule:run.
 *
 * `Schedule::call()` invokes the command in the SAME process and needs nothing
 * beyond PHP itself.
 *
 * These tests assert the mechanism rather than the symptom, because a test
 * cannot switch proc_open off for itself: disable_functions is PHP_INI_SYSTEM
 * and the flag is read at startup.
 */
class SharedHostingSchedulerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_scheduled_task_needs_a_separate_process(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events, 'The BMP schedule has entries; an empty list means they stopped registering.');

        foreach ($events as $event) {
            $this->assertInstanceOf(
                CallbackEvent::class,
                $event,
                "[{$event->getSummaryForDisplay()}] is a process-spawning Schedule::command(). Symfony's Process "
                ."refuses to start without proc_open, which shared hosting commonly disables, so this task would\n"
                .'never run there. Register it with Schedule::call() and invoke the command with Artisan::call().',
            );
        }
    }

    #[Test]
    public function both_bmp_jobs_are_scheduled_and_keep_their_cadence(): void
    {
        $byName = [];

        foreach (app(Schedule::class)->events() as $event) {
            $byName[$event->getSummaryForDisplay()] = $event;
        }

        $this->assertArrayHasKey('bmp:notifications:sweep', $byName);
        $this->assertArrayHasKey('bmp:day-plans:carry-forward', $byName);

        // Hourly, and just after midnight - unchanged by the hosting fix.
        $this->assertSame('0 * * * *', $byName['bmp:notifications:sweep']->expression);
        $this->assertSame('15 0 * * *', $byName['bmp:day-plans:carry-forward']->expression);

        // The overlap guard survives too. Its lock lives in the cache store,
        // which is the database here, so it needs no extra service.
        foreach ($byName as $event) {
            $this->assertTrue($event->withoutOverlapping, 'The overlap guard must survive the change.');
        }
    }

    #[Test]
    public function every_scheduled_task_actually_runs_and_reports_success(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $result = $event->run(app());

            $this->assertNotFalse(
                $result,
                "[{$event->getSummaryForDisplay()}] reported failure. A callback returning false is recorded as a "
                .'failed run, so the exit code must be translated.',
            );
        }
    }

    #[Test]
    public function running_the_sweep_twice_queues_nothing_twice(): void
    {
        // Idempotence is what makes a cron that fires more often than expected
        // - or a retried run - safe on hosting with no overlap protection.
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->getSummaryForDisplay() === 'bmp:notifications:sweep');

        $this->assertCount(1, $events);

        $event = $events->first();
        $event->run(app());
        $first = NotificationDispatch::query()->count();

        $event->run(app());
        $this->assertSame(
            $first,
            NotificationDispatch::query()->count(),
            'A second sweep in the same window must not queue anything again.',
        );
    }
}
