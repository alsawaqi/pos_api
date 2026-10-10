<?php

use App\Http\Middleware\AttachSentryDeviceContext;
use App\Http\Middleware\EnsureCompanyActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveQrSession;
use App\Http\Middleware\RestrictCustomerTablet;
use App\Http\Middleware\RestrictKitchenDisplay;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // §11.5 — load the broadcast channel definitions (routes/channels.php).
    // The channel-AUTH endpoint itself is defined explicitly in routes/api.php
    // as /api/v1/broadcasting/auth, behind the `pos_device` guard + JSON
    // middleware, so it lives on the device API contract (not the default
    // web-session /broadcasting/auth, which this token-only API has no use for).
    // LAUNCH-P6 fix order 1 (F-4) — a customer tablet's token joins no live channel.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['api', 'auth:pos_device', RestrictKitchenDisplay::class, RestrictCustomerTablet::class]])
    ->withMiddleware(function (Middleware $middleware): void {
        // QR client secrets and checkout idempotency keys are opaque values.
        // Preserve their exact bytes so JSON binding and later header checks
        // agree, and so two distinct client request keys never collapse.
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/v1/device/kitchen-v2/*'),
            'client_secret',
            'client_request_id',
        ]);

        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/v1/device/kitchen-v2/*')]);

        $middleware->alias([
            'qr.session' => ResolveQrSession::class,
        ]);

        // Force every /api/* request to be treated as JSON regardless of the
        // client's Accept header, so auth/validation/not-found failures return
        // a JSON envelope (or a 401) instead of an HTML page or a redirect to
        // a non-existent `login` route. Pairs with shouldRenderJsonWhen below.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // Phase D1 — Sentry device context (device/company/branch/request-id
        // tags). No-op when SENTRY_LARAVEL_DSN is unset; runs before the
        // route-level auth:pos_device but resolves the device via the lazy
        // viaRequest guard explicitly.
        $middleware->api(append: [
            EnsureCompanyActive::class,
            AttachSentryDeviceContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*', 'broadcasting/auth')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'device_reactivation_required',
                ], 401);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'broadcasting/auth'),
        );

        // Phase D1 — report unhandled exceptions to Sentry (blueprint §9.12
        // "errors from each surface… Laravel, …"). A complete no-op when
        // SENTRY_LARAVEL_DSN is unset, so local dev + the test suite are
        // unaffected.
        Integration::handles($exceptions);
    })->create();
