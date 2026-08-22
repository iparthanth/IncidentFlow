<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts the boolean spellings people actually type.
 *
 * Laravel's `boolean` validation rule is strict: `true`, `false`, `1`, `0`,
 * `"1"` and `"0"` pass, and the string `"true"` does not. But `?active_only=true`
 * is the single most natural way to write a flag in a query string, and every
 * HTTP client — curl, a browser's `URLSearchParams`, most SDKs — produces
 * exactly that. Rejecting it with a 422 is a papercut that makes the API feel
 * broken to anyone integrating against it for the first time.
 *
 * The allowlist is deliberate rather than a blanket rewrite of every "true" in
 * the request: a search of `?q=true` is a perfectly legitimate query, and
 * silently turning it into `?q=1` would be a far stranger bug than the one this
 * fixes.
 */
final class NormalizeBooleanParameters
{
    /**
     * Parameters that are booleans everywhere they appear in this API.
     *
     * @var list<string>
     */
    private const array BOOLEAN_PARAMETERS = [
        'active_only',
        'assignable_only',
        'public',
        'public_only',
        'unread_only',
        'include_deleted',
        'is_active',
        'is_public',
    ];

    private const array TRUTHY = ['true', 'yes', 'on'];

    private const array FALSY = ['false', 'no', 'off', ''];

    public function handle(Request $request, Closure $next): Response
    {
        $replacements = [];

        foreach (self::BOOLEAN_PARAMETERS as $parameter) {
            if (! $request->has($parameter)) {
                continue;
            }

            $value = $request->input($parameter);

            if (! is_string($value)) {
                continue;
            }

            $normalised = strtolower(trim($value));

            if (in_array($normalised, self::TRUTHY, strict: true)) {
                $replacements[$parameter] = '1';
            } elseif (in_array($normalised, self::FALSY, strict: true)) {
                $replacements[$parameter] = '0';
            }
            // Anything else is left alone so validation can reject it properly,
            // rather than being coerced into a value the caller never meant.
        }

        if ($replacements !== []) {
            $request->merge($replacements);
        }

        return $next($request);
    }
}
