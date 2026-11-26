<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes and schedule
|--------------------------------------------------------------------------
|
| Financial maintenance runs in the quiet hours and every command is
| idempotent, so a failed or overlapping run can be retried safely.
|
*/

Schedule::command('chama:accrue-penalties')
    ->dailyAt('01:10')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('chama:reconcile-ledgers')
    ->dailyAt('01:40')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('chama:renew-subscriptions')
    ->dailyAt('02:10')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('chama:send-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:10');
Schedule::command('queue:prune-failed --hours=168')->weeklyOn(1, '03:30');
Schedule::command('model:prune')->dailyAt('03:45');
