<?php

use Illuminate\Support\Facades\Schedule;

// Webhook fallback: poll generations that never received a final webhook.
Schedule::command('generations:sync')->everyMinute();
