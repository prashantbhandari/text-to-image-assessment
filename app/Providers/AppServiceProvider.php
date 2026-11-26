<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters, attached to routes with `throttle:<name>`.
     * Authenticated routes are limited per user, public ones per IP.
     * Exceeding a limit returns 429, shown to the user as a friendly toast
     * (see bootstrap/app.php).
     */
    private function configureRateLimiting(): void
    {
        $who = fn (Request $r) => $r->user()?->id ?: $r->ip();

        // Sign-up: stops mass account creation from one address.
        RateLimiter::for('register', fn (Request $r) => [
            Limit::perMinute(5)->by($r->ip()),
            Limit::perHour(20)->by($r->ip()),
        ]);

        // Login: the per-email+IP lockout (5 failures) lives in LoginRequest. This is the
        // per-IP ceiling that stops one address spraying many different emails.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));

        // Password reset mail, reset, confirm and change: guessing / mail-bombing protection.
        RateLimiter::for('sensitive', fn (Request $r) => [
            Limit::perMinute(5)->by($who($r)),
            Limit::perHour(30)->by($who($r)),
        ]);

        // Image creation: a user can't flood Pixelforge (its limit is 60 req/min per account).
        RateLimiter::for('generate', fn (Request $r) => [
            Limit::perMinute(6)->by($who($r)),
            Limit::perHour(60)->by($who($r)),
        ]);

        // Other state-changing actions (buy credits, delete, profile edits).
        RateLimiter::for('actions', fn (Request $r) => Limit::perMinute(30)->by($who($r)));

        // Pixelforge webhooks: generous, because a dropped event only delays a result.
        RateLimiter::for('webhook', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
    }
}
