<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes timeline events onto Redis for the realtime tier to fan out.
 *
 * Two rules govern every call site:
 *
 * 1. **Publish after commit, never inside the transaction.** Redis has no idea
 *    the transaction exists; publishing early would announce a state change
 *    that a rollback then erases, and subscribers would show users an incident
 *    status that does not exist in PostgreSQL.
 *
 * 2. **A publish failure must not fail the request.** PostgreSQL is the source
 *    of truth; Redis is a delivery optimisation. If the broker is down the
 *    incident is still recorded, the API still returns 200, and clients fall
 *    back to polling/refetch. Turning a degraded broker into 500s would be
 *    exactly the wrong trade for an incident-management tool — it fails hardest
 *    when things are already going badly.
 */
final class RealtimePublisher
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly LoggerInterface $logger,
        private readonly string $connection,
        private readonly string $channelPrefix,
        private readonly bool $enabled,
    ) {}

    public function publish(RealtimeEvent $event): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $channel = $event->channel($this->channelPrefix);

        try {
            $this->redis->connection($this->connection)->publish(
                $channel,
                json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );

            $this->logger->debug('realtime.published', [
                'channel' => $channel,
                'event_id' => $event->id,
                'event_type' => $event->type,
                'request_id' => $event->requestId,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('realtime.publish_failed', [
                'channel' => $channel,
                'event_id' => $event->id,
                'event_type' => $event->type,
                'request_id' => $event->requestId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @param iterable<RealtimeEvent> $events */
    public function publishMany(iterable $events): int
    {
        $published = 0;
        foreach ($events as $event) {
            $published += $this->publish($event) ? 1 : 0;
        }

        return $published;
    }
}
