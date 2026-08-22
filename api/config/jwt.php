<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Signing keys
    |---------------------------------------------------------------------------
    |
    | RS256 rather than HS256 deliberately. The realtime service must verify
    | tokens this API mints, and with an asymmetric key it can do so holding
    | only the public half — a compromise of the realtime tier cannot forge an
    | administrator token. Generate a pair with `php artisan jwt:keys`.
    |
    | Keys may be supplied inline (base64 or raw PEM) for platforms that only
    | offer environment variables, or as a path for container secret mounts.
    |
    */

    'private_key' => env('JWT_PRIVATE_KEY'),
    'private_key_path' => env('JWT_PRIVATE_KEY_PATH', storage_path('keys/jwt-private.pem')),
    'public_key' => env('JWT_PUBLIC_KEY'),
    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', storage_path('keys/jwt-public.pem')),
    'passphrase' => env('JWT_PASSPHRASE'),

    'algorithm' => 'RS256',

    /*
    |---------------------------------------------------------------------------
    | Issuer and audiences
    |---------------------------------------------------------------------------
    |
    | Two audiences, one issuer. The separation is what stops a REST access
    | token from being replayed as a stream ticket: EventSource cannot set
    | headers, so a ticket travels in a query string where it is far more
    | likely to end up in an access log. Tickets are therefore a distinct,
    | very short-lived credential that grants nothing but a read stream.
    |
    */

    'issuer' => env('JWT_ISSUER', 'incidentflow-api'),

    'audiences' => [
        'api' => env('JWT_AUDIENCE_API', 'incidentflow-api'),
        'realtime' => env('JWT_AUDIENCE_REALTIME', 'incidentflow-realtime'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Lifetimes (seconds)
    |---------------------------------------------------------------------------
    |
    | Access tokens are short because they are not revocable by construction;
    | the ceiling on damage from a leaked one is its TTL. Refresh tokens are
    | long but rotate on every use and are revocable per family.
    |
    */

    'ttl' => [
        'access' => (int) env('JWT_ACCESS_TTL', 900),          // 15 minutes
        'refresh' => (int) env('JWT_REFRESH_TTL', 2_592_000),  // 30 days
        'realtime' => (int) env('JWT_REALTIME_TTL', 60),       // 1 minute
    ],

    /** Tolerance for clock drift between the API, the realtime node and the client. */
    'leeway' => (int) env('JWT_LEEWAY', 30),

    /*
    |---------------------------------------------------------------------------
    | Denylist
    |---------------------------------------------------------------------------
    |
    | Stateless tokens cannot be withdrawn, which makes "log out everywhere"
    | a lie unless something remembers. On logout the token's `jti` is stored
    | in the cache for exactly its remaining lifetime — bounded memory, and
    | the guarantee holds even though the token itself is self-contained.
    |
    */

    'denylist' => [
        'enabled' => (bool) env('JWT_DENYLIST_ENABLED', true),
        // null falls back to the application's default cache store, which keeps
        // the test suite on the array driver without special-casing.
        'cache_store' => env('JWT_DENYLIST_STORE'),
        'prefix' => 'jwt:denylist:',
    ],

    /*
    |---------------------------------------------------------------------------
    | Refresh cookie
    |---------------------------------------------------------------------------
    |
    | The refresh token is delivered as an HttpOnly cookie so that JavaScript —
    | and therefore any XSS payload — cannot read it. The access token lives in
    | memory only and is never written to localStorage.
    |
    */

    'refresh_cookie' => [
        'name' => env('JWT_REFRESH_COOKIE', 'incidentflow_refresh'),
        'path' => '/api/v1/auth',
        'domain' => env('JWT_REFRESH_COOKIE_DOMAIN'),
        'secure' => (bool) env('JWT_REFRESH_COOKIE_SECURE', true),
        'same_site' => env('JWT_REFRESH_COOKIE_SAMESITE', 'strict'),
    ],
];
