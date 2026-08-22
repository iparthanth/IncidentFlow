<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendIncidentNotification;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Re-queues notifications that were written but never dispatched.
 *
 * The gap this closes: `NotificationDispatcher::prepare()` writes rows inside
 * the incident transaction, and `dispatch()` enqueues them after the commit.
 * If the process dies in between — a deploy, an OOM kill, a Redis blip — the
 * rows exist in `pending` and no job was ever created. Nothing is lost, but
 * nothing is delivered either, and a SEV-1 page that silently never arrives is
 * the worst failure mode this system has.
 *
 * Scheduled every five minutes. Safe to run concurrently: the job itself
 * returns early on any notification already sent or read.
 */
final class RetryStaleNotifications extends Command
{
    protected $signature = 'notifications:retry-stale {--dry-run : Report what would be re-queued without dispatching}';

    protected $description = 'Re-queue notifications that were persisted but never made it onto the queue';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $stale = $dispatcher->stalePending();

        if ($stale->isEmpty()) {
            $this->info('No stale notifications.');

            return self::SUCCESS;
        }

        $this->warn("Found {$stale->count()} notification(s) stuck in pending.");

        if ($this->option('dry-run')) {
            $this->table(
                ['ULID', 'Incident', 'Type', 'Created'],
                $stale->map(fn ($n): array => [
                    $n->ulid,
                    $n->incident_id ?? '—',
                    $n->type,
                    $n->created_at?->diffForHumans() ?? '—',
                ])->all(),
            );

            return self::SUCCESS;
        }

        foreach ($stale as $notification) {
            SendIncidentNotification::dispatch($notification->getKey());
        }

        $this->info("Re-queued {$stale->count()} notification(s).");

        return self::SUCCESS;
    }
}
