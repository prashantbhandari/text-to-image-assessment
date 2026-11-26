<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the Laravel scheduler from a URL, for hosts with no shell where the control
 * panel's cron can only fetch a URL (or where an external pinger such as cron-job.org
 * is used). Call it every minute: GET /cron/<CRON_TOKEN>.
 *
 * Disabled (404) unless CRON_TOKEN is set. The token is compared in constant time and
 * the route is rate limited. Anyone holding the token can only trigger the same
 * scheduled work the server's cron would, nothing more.
 */
class CronController extends Controller
{
    public function __invoke(string $token): Response
    {
        $expected = (string) config('pixelforge.cron_token');

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        Artisan::call('schedule:run');

        return response('ok', 200, ['Content-Type' => 'text/plain']);
    }
}
