<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where delivered artifacts are stored
    |--------------------------------------------------------------------------
    |
    | The private local disk by default. A report aggregates a business's whole
    | position, so it must never land on a publicly readable disk: 'public'
    | would make every artifact reachable by URL to anyone who guessed a path.
    |
    */

    'disk' => env('REPORTS_DISK', 'local'),

];
