<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\DomainException;
use App\Exceptions\InvalidTokenException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\HandleIdempotency;
use App\Http\Middleware\NormalizeBooleanParameters;
use App\Support\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // Versioned from day one. Retrofitting a version prefix onto a live
        // API means breaking every client that already hard-coded /api/.
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * The application only ever receives traffic through nginx, so the
         * proxy headers are the only source of the real client IP. Without
         * this, rate limiting buckets every request under the proxy's address
         * and one noisy client throttles everybody.
         *
         * Trusting `*` is correct precisely *because* nothing else can reach
         * the container: in production the API port is not published, and
         * nginx overwrites X-Forwarded-For rather than appending to it.
         */
        $middleware->trustProxies(at: '*');

        $middleware->api(prepend: [
            ForceJsonResponse::class,
            AssignRequestId::class,
            // Runs before validation so `?active_only=true` is accepted rather
            // than 422'd on a technicality of Laravel's `boolean` rule.
            NormalizeBooleanParameters::class,
        ]);

        $middleware->alias([
            'auth.jwt' => AuthenticateJwt::class,
            'organization' => EnsureOrganizationContext::class,
            'idempotency' => HandleIdempotency::class,
        ]);

        // Stateless API: no cookies to protect with CSRF tokens, and the
        // refresh cookie is SameSite=Strict + HttpOnly, which is the actual
        // control. The session-driven web group still runs it for Horizon.
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request)
        );

        // Every reported exception carries the correlation id, so a Sentry
        // event and an nginx access-log line can be joined without guessing.
        $exceptions->context(static fn (): array => [
            'request_id' => app(RequestContext::class)->requestId(),
        ]);

        // Expected client-side failures are noise in an error tracker.
        $exceptions->dontReport([
            DomainException::class,
            InvalidTokenException::class,
        ]);
    })
    ->create();
