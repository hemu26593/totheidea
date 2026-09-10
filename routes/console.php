<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Why these are Schedule::call() and not Schedule::command()
|--------------------------------------------------------------------------
|
| Schedule::command() runs the command in a SEPARATE PROCESS. Laravel builds
| it with Symfony's Process, which requires proc_open - and that happens on the
| foreground path too, not only under runInBackground(). Shared hosting very
| often lists proc_open in disable_functions, and there Process throws
| "The Process class relies on proc_open" and NEITHER scheduled job ever runs.
| Nothing reports it, because the failure is inside schedule:run.
|
| Schedule::call() invokes the command inside the SAME process, so the
| scheduler works whether or not proc_open is available. The cadence, the
| overlap guard and the commands themselves are unchanged; only the way they
| are launched is.
|
| Artisan::call() returns the command's exit code. Returning `false` from the
| callback would make Laravel record the run as failed, so the code is
| translated: 0 means success.
|
*/

/**
 * Run an Artisan command in-process and report success to the scheduler.
 */
$runInProcess = static fn (string $command): callable => static fn (): bool => Artisan::call($command) === 0;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Notification sweep
|--------------------------------------------------------------------------
|
| Hourly, because the nine SOW triggers do not share a cadence: "that morning"
| and "the same evening" cannot both be served by a single daily run, and
| running hourly costs nothing when there is no qualifying work.
|
| Running often is safe by design. The sweep identifies work and the dedupe key
| decides whether it has already been handled, so a duplicated run, an overlap
| or a manual invocation all converge on the same set of sends.
|
| withoutOverlapping is belt-and-braces: correctness does not depend on it,
| but two sweeps racing would do the same work twice and lose one of them to a
| unique-key violation for nothing. Its lock lives in the cache store, which is
| the database here - so it needs no extra service.
|
*/
Schedule::call($runInProcess('bmp:notifications:sweep'))
    ->name('bmp:notifications:sweep')
    ->hourly()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Day plan carry-forward
|--------------------------------------------------------------------------
|
| "Unfinished tasks move to the next day automatically." Just after midnight,
| so a participant opening the app in the morning sees yesterday's unfinished
| work waiting on today.
|
| Running it twice is harmless by design: the sweep only sees `planned` items
| and settles each original as it copies it, so a retry cannot duplicate a
| task.
|
*/
Schedule::call($runInProcess('bmp:day-plans:carry-forward'))
    ->name('bmp:day-plans:carry-forward')
    ->dailyAt('00:15')
    ->withoutOverlapping();
