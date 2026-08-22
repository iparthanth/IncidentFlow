<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IncidentAssigneeController;
use App\Http\Controllers\Api\V1\IncidentCommanderController;
use App\Http\Controllers\Api\V1\IncidentCommentController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\IncidentEventController;
use App\Http\Controllers\Api\V1\IncidentExportController;
use App\Http\Controllers\Api\V1\IncidentSeverityController;
use App\Http\Controllers\Api\V1\IncidentStatusController;
use App\Http\Controllers\Api\V1\IncidentUpdateController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PostmortemController;
use App\Http\Controllers\Api\V1\RealtimeTicketController;
use App\Http\Controllers\Api\V1\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Mounted under /api/v1 (see bootstrap/app.php).
|
| Three middleware layers stack up, each answering a different question:
|   auth.jwt      who are you?
|   organization  which tenant are you acting in, and do you belong to it?
|   throttle:*    are you asking too often?
|
| Authorization — *what* you may do — is deliberately not a middleware. It
| lives in policies, invoked per action, because it depends on the resource
| and not merely on the route.
|
*/

/**
 * Probes. Unauthenticated by design: a load balancer has no credentials, and
 * requiring them would mean an auth outage looks identical to a total outage.
 */
Route::prefix('health')->group(function (): void {
    Route::get('live', [HealthController::class, 'live']);
    Route::get('ready', [HealthController::class, 'ready']);
});

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');

    Route::middleware('auth.jwt')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutEverywhere']);
    });
});

Route::middleware(['auth.jwt', 'throttle:api'])->group(function (): void {
    // Listing your own organizations cannot require an organization context —
    // it is the call a client makes precisely because it does not have one yet.
    Route::get('organizations', [OrganizationController::class, 'index']);

    Route::middleware('organization')->group(function (): void {
        Route::post('realtime/ticket', RealtimeTicketController::class)->middleware('throttle:realtime');

        Route::get('organization', [OrganizationController::class, 'show']);
        Route::patch('organization', [OrganizationController::class, 'update'])->middleware('throttle:writes');

        Route::get('members', [MemberController::class, 'index']);
        Route::post('members', [MemberController::class, 'store'])->middleware('throttle:writes');
        Route::patch('members/{member}', [MemberController::class, 'update'])->middleware('throttle:writes');
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->middleware('throttle:writes');

        Route::get('services', [ServiceController::class, 'index']);
        Route::get('services/{service}', [ServiceController::class, 'show']);
        Route::post('services', [ServiceController::class, 'store'])->middleware('throttle:writes');
        Route::patch('services/{service}', [ServiceController::class, 'update'])->middleware('throttle:writes');
        Route::delete('services/{service}', [ServiceController::class, 'destroy'])->middleware('throttle:writes');

        /**
         * Declared before `incidents/{incident}` on purpose: Laravel matches
         * routes in definition order, so a later literal segment would be
         * swallowed by the wildcard and "export" would be looked up as an id.
         */
        Route::get('incidents/export', IncidentExportController::class)->middleware('throttle:exports');

        Route::get('incidents', [IncidentController::class, 'index']);
        Route::post('incidents', [IncidentController::class, 'store'])
            // Required, not optional: a retried "report incident" that creates
            // a second SEV-1 splits the response across two records.
            ->middleware(['throttle:writes', 'idempotency:required']);
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::patch('incidents/{incident}', [IncidentController::class, 'update'])->middleware('throttle:writes');
        Route::delete('incidents/{incident}', [IncidentController::class, 'destroy'])->middleware('throttle:writes');

        Route::post('incidents/{incident}/status', IncidentStatusController::class)
            ->middleware(['throttle:writes', 'idempotency']);
        Route::post('incidents/{incident}/severity', IncidentSeverityController::class)->middleware('throttle:writes');
        Route::put('incidents/{incident}/commander', IncidentCommanderController::class)->middleware('throttle:writes');

        Route::get('incidents/{incident}/assignees', [IncidentAssigneeController::class, 'index']);
        Route::post('incidents/{incident}/assignees', [IncidentAssigneeController::class, 'store'])->middleware('throttle:writes');
        Route::delete('incidents/{incident}/assignees/{user}', [IncidentAssigneeController::class, 'destroy'])->middleware('throttle:writes');

        Route::get('incidents/{incident}/events', [IncidentEventController::class, 'index']);

        Route::get('incidents/{incident}/updates', [IncidentUpdateController::class, 'index']);
        Route::post('incidents/{incident}/updates', [IncidentUpdateController::class, 'store'])
            ->middleware(['throttle:writes', 'idempotency']);

        Route::get('incidents/{incident}/comments', [IncidentCommentController::class, 'index']);
        Route::post('incidents/{incident}/comments', [IncidentCommentController::class, 'store'])
            ->middleware(['throttle:writes', 'idempotency']);
        Route::delete('comments/{comment}', [IncidentCommentController::class, 'destroy'])->middleware('throttle:writes');

        Route::get('postmortems', [PostmortemController::class, 'index']);
        Route::get('incidents/{incident}/postmortem', [PostmortemController::class, 'show']);
        Route::put('incidents/{incident}/postmortem', [PostmortemController::class, 'upsert'])->middleware('throttle:writes');
        Route::post('incidents/{incident}/postmortem/publish', [PostmortemController::class, 'publish'])->middleware('throttle:writes');

        Route::get('metrics/summary', [MetricsController::class, 'summary']);
        Route::get('metrics/trends', [MetricsController::class, 'trends']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
    });
});
