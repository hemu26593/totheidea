<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
| unique-key violation for nothing.
|
*/
Schedule::command('bmp:notifications:sweep')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
