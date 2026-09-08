<?php

declare(strict_types=1);

use App\Domain\Notifications\Triggers;

return [

    /*
    |--------------------------------------------------------------------------
    | The nine SOW section 7 triggers
    |--------------------------------------------------------------------------
    |
    | The triggers are CODE CONSTANTS, not rows. None is invented and none is
    | user-configurable in v1, which is why there is no triggers table and why
    | this list is a class map rather than a database seed.
    |
    | Order is the SOW's order.
    |
    */

    'triggers' => [
        Triggers\SessionUpcomingTrigger::class,
        Triggers\IntakeIncompleteTrigger::class,
        Triggers\AssignmentDueTrigger::class,
        Triggers\AssignmentOverdueTrigger::class,
        Triggers\DailyDashboardMissingTrigger::class,
        Triggers\DashboardMissedThreeDaysTrigger::class,
        Triggers\PaymentDueTrigger::class,
        Triggers\AttendanceBelowThresholdTrigger::class,
        Triggers\WeeklyBatchSummaryTrigger::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing
    |--------------------------------------------------------------------------
    |
    | Every number below is from the SOW section 7 table. None is invented; a
    | value that the SOW does not state does not appear here.
    |
    */

    'timing' => [

        // Trigger 1 - "3 days before + that morning".
        'session_upcoming_lead_days' => [3, 0],

        // Trigger 3 - "2 days before + on the day".
        'assignment_due_lead_days' => [2, 0],

        // Trigger 4 - "next day, then every 3".
        'assignment_overdue_first_day' => 1,
        'assignment_overdue_interval_days' => 3,

        // Trigger 6 - "missed 3 days running".
        'dashboard_missed_days' => 3,

        // Trigger 7 - "on date, then every 2".
        'payment_due_interval_days' => 2,

        // Trigger 9 - "Monday morning". ISO-8601 day of week.
        'weekly_summary_day' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => env('NOTIFICATIONS_QUEUE', 'default'),

    // Retries per dispatch. A failed send is retried with backoff; the attempt
    // count and the last error are recorded on the row either way.
    'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 3),

    // Seconds between retries.
    'backoff' => [60, 300, 900],

];
