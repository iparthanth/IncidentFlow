<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Realtime fan-out
    |---------------------------------------------------------------------------
    |
    | Laravel publishes incident events onto Redis; the Node service subscribes
    | and pushes them to browsers. PostgreSQL remains the source of truth —
    | Redis pub/sub is fire-and-forget and carries no durability guarantee, so
    | a dropped message costs a refetch and never data.
    |
    | Disabling this leaves the API fully functional with a polling frontend,
    | which is exactly what you want to be able to do when the broker is the
    | thing that is broken.
    |
    */

    'enabled' => (bool) env('REALTIME_ENABLED', true),

    /** Redis connection name from config/database.php. */
    'connection' => env('REALTIME_REDIS_CONNECTION', 'realtime'),

    /**
     * Channel names are `{prefix}:org:{id}`. Must match REDIS_CHANNEL_PREFIX
     * in the realtime service, or the two sides talk past each other.
     */
    'channel_prefix' => env('REALTIME_CHANNEL_PREFIX', 'incidentflow'),

    /** Public URL the browser opens its stream against. */
    'public_url' => env('REALTIME_PUBLIC_URL', '/realtime'),
];
