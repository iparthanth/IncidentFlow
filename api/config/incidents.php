<?php

declare(strict_types=1);

return [

    'pagination' => [
        'default_per_page' => 25,
        // Bounded because `?per_page=100000` is a denial-of-service request
        // wearing a pagination parameter as a disguise.
        'max_per_page' => 100,
    ],

    'export' => [
        /** Hard ceiling on a CSV export, streamed in chunks of this size. */
        'max_rows' => (int) env('INCIDENT_EXPORT_MAX_ROWS', 50_000),
        'chunk_size' => 500,
    ],

    'notifications' => [
        /** Rows still `pending` after this long are re-queued by the sweeper. */
        'stale_after_minutes' => (int) env('NOTIFICATION_STALE_AFTER_MINUTES', 5),
        'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 5),
    ],

    'idempotency' => [
        'retention_hours' => (int) env('IDEMPOTENCY_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Only the auth limits are configurable, and only because an environment
    | where every client shares one source IP -- a browser driving an
    | end-to-end suite, say -- legitimately needs a higher ceiling than a
    | public deployment. The defaults are the production values; raise them in
    | docker-compose, never in a deployed environment.
    |
    */
    'rate_limits' => [
        'auth_per_ip' => (int) env('AUTH_RATE_LIMIT_PER_IP', 10),
        'auth_per_identity' => (int) env('AUTH_RATE_LIMIT_PER_IDENTITY', 5),
    ],

    'retention' => [
        /**
         * Audit logs are kept effectively forever by default: the whole point
         * of the table is to answer questions asked long after the fact. Set a
         * value only if a data-protection policy requires one.
         */
        'audit_log_days' => env('AUDIT_LOG_RETENTION_DAYS') !== null
            ? (int) env('AUDIT_LOG_RETENTION_DAYS')
            : null,
        'read_notification_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    ],
];
