<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\JwtGuard;
use App\Exceptions\InvalidTokenException;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects unauthenticated requests with a reason the client can act on.
 *
 * Laravel's stock `auth` middleware answers only "no". That is not enough here:
 * the frontend must distinguish *expired* (silently refresh and retry — the
 * user notices nothing) from *invalid or revoked* (drop the session and send
 * them to the login screen). Collapsing both into 401 either strands users who
 * merely idled, or traps a revoked session in a refresh loop.
 *
 * The reason is a coarse code, never a description of what was wrong with the
 * signature — an attacker probing tokens should learn nothing.
 */
final class AuthenticateJwt
{
    public function handle(Request $request, Closure $next, string $guard = 'api'): Response
    {
        $authGuard = Auth::guard($guard);

        if ($authGuard->check()) {
            Auth::shouldUse($guard);

            return $next($request);
        }

        $reason = $authGuard instanceof JwtGuard
            ? $authGuard->lastFailure()
            : null;

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => match ($reason?->reason) {
                    InvalidTokenException::REASON_EXPIRED => 'token_expired',
                    InvalidTokenException::REASON_REVOKED => 'token_revoked',
                    default => 'unauthenticated',
                },
                'message' => $reason?->publicMessage() ?? 'Authentication is required to access this resource.',
                'request_id' => $request->headers->get(AssignRequestId::HEADER),
            ],
        ], Response::HTTP_UNAUTHORIZED));
    }
}
