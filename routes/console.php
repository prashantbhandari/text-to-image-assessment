<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Webhook fallback: poll generations that never received a final webhook.
//
// Runs IN-PROCESS (Schedule::call) rather than via Schedule::command(), which would spawn a
// separate `php artisan` process. Many shared hosts disable process spawning or expose a PHP
// binary that cannot run the CLI, and an in-process call also works when the scheduler is
// triggered by URL (GET /cron/<token>). withoutOverlapping stops two runs polling at once.
Schedule::call(fn () => Artisan::call('generations:sync'))
    ->name('generations:sync')
    ->everyMinute()
    ->withoutOverlapping(5);
