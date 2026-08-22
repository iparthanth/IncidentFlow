<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/**
 * Scheduled work.
 *
 * `withoutOverlapping()` and `onOneServer()` matter more here than they look:
 * the scheduler runs on every API container, so without a shared lock each
 * task would execute once per replica — three copies of the prune, three
 * copies of every re-queued page. Both rely on a shared cache lock, which
 * Redis provides.
 */

// Closes the gap between "notification row written" and "job enqueued" when a
// process dies in between. Frequent, because the thing it rescues is a page.
Schedule::command('notifications:retry-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Housekeeping for the tables that grow without bound. Off-peak, since it is
// the only scheduled task that issues bulk deletes.
Schedule::command('incidentflow:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Laravel's own sweeps: stale batches and long-dead failed jobs.
Schedule::command('queue:prune-batches --hours=48')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=336')->weekly()->onOneServer();
