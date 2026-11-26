<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Behind ngrok / Laravel Cloud TLS is terminated at the proxy: trust X-Forwarded-* so
        // generated URLs (assets, redirects) use https.
        $middleware->trustProxies(at: '*');

        // Pixelforge cannot send a CSRF token; the webhook is authenticated by its HMAC signature.
        $middleware->validateCsrfTokens(except: ['webhooks/pixelforge']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Rate limit hit: show a friendly toast instead of a raw error page.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return null; // JSON clients keep the standard 429 + Retry-After
            }
            $wait = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $when = $wait >= 90 ? 'in about '.ceil($wait / 60).' minutes' : "in {$wait} seconds";

            return back()->with('error', "Too many attempts. Please try again {$when}.");
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
