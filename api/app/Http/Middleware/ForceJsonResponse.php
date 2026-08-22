<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees the API answers in JSON even when the caller forgot to ask.
 *
 * Without this, a curl without `Accept: application/json` that trips a
 * validation error gets an HTML redirect, and a 500 gets a stack-trace page.
 * Both are useless to a machine and the second is a disclosure risk.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
