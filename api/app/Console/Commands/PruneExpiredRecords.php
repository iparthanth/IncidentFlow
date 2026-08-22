<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\RefreshToken;
use Illuminate\Console\Command;

/**
 * Housekeeping for the three tables that grow without bound.
 *
 * Note what is *not* pruned: `audit_logs` and `incident_events`. Both are
 * permanent records by design — the whole value of an audit trail is that it
 * answers questions asked long after anyone thought to ask them. A retention
 * period can be configured for audit logs when a data-protection policy
 * demands one, but the default is to keep them.
 */
final class PruneExpiredRecords extends Command
{
    protected $signature = 'incidentflow:prune {--dry-run : Report counts without deleting}';

    protected $description = 'Delete expired idempotency keys, dead refresh tokens and old read notifications';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Expired idempotency keys. Past their window they cannot serve a
        // replay, so keeping them only grows the unique index.
        $expiredKeys = IdempotencyKey::query()->expired();
        $keyCount = $expiredKeys->count();

        // Refresh tokens that are both revoked and long past expiry. Revoked
        // ones are kept for a grace period so family-reuse detection still has
        // something to detect against.
        $deadTokens = RefreshToken::query()
            ->where('expires_at', '<', now()->subDays(30))
            ->whereNotNull('revoked_at');
        $tokenCount = $deadTokens->count();

        $retentionDays = (int) config('incidents.retention.read_notification_days');
        $oldNotifications = Notification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($retentionDays));
        $notificationCount = $oldNotifications->count();

        $this->table(
            ['Table', 'Rows', 'Criterion'],
            [
                ['idempotency_keys', $keyCount, 'expires_at in the past'],
                ['refresh_tokens', $tokenCount, 'revoked and expired over 30 days ago'],
                ['notifications', $notificationCount, "read over {$retentionDays} days ago"],
            ],
        );

        if ($dryRun) {
            $this->comment('Dry run — nothing deleted.');

            return self::SUCCESS;
        }

        $expiredKeys->delete();
        $deadTokens->delete();
        $oldNotifications->delete();

        $this->info('Pruned '.($keyCount + $tokenCount + $notificationCount).' row(s).');

        return self::SUCCESS;
    }
}
