<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This service serves no HTML. The SPA is a separate container behind the same
| nginx origin, and nginx routes everything that is not /api or /realtime to it.
|
| The one route here exists so that a request reaching the API container's root
| — a misconfigured proxy, a health check pointed at the wrong path, someone
| curling the container directly — gets a useful answer instead of a Laravel
| welcome page that would suggest the deployment is fine when it is not.
|
| The Horizon dashboard mounts itself at /horizon and is gated by the
| `viewHorizon` gate in AppServiceProvider.
|
*/

Route::get('/', static fn (): JsonResponse => response()->json([
    'service' => 'incidentflow-api',
    'version' => config('app.version'),
    'documentation' => '/api/v1',
    'health' => '/api/v1/health/live',
    'message' => 'This is the API container. The web application is served from the same origin at /.',
]));
