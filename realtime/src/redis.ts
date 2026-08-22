import { Redis, type RedisOptions } from 'ioredis';
import type { Logger } from 'pino';
import type { ChannelSubscriber } from './hub.js';
import { RealtimeEventSchema, ENVELOPE_VERSION, type RealtimeEvent } from './types.js';
import { eventsReceived } from './metrics.js';

export interface RedisSubscriberDeps {
  url: string;
  logger: Logger;
  onEvent: (event: RealtimeEvent) => void;
  /** Channels to restore after a reconnect; supplied by the hub. */
  activeChannels: () => string[];
}

const RETRY_BASE_MS = 200;
const RETRY_MAX_MS = 10_000;

/**
 * Dedicated Redis connection in subscribe mode.
 *
 * Redis pub/sub is fire-and-forget: a message published while this node is
 * reconnecting is gone. That is an accepted trade-off — PostgreSQL, not Redis,
 * is the source of truth for the incident timeline, so a dropped frame costs a
 * refetch and never data. The hub's replay buffer plus the client's
 * `Last-Event-ID` handling turn that into a visible "gap" signal rather than a
 * silently stale UI.
 */
export class RedisChannelSubscriber implements ChannelSubscriber {
  private readonly client: Redis;
  private readonly logger: Logger;
  private ready = false;

  constructor(private readonly deps: RedisSubscriberDeps) {
    this.logger = deps.logger.child({ component: 'redis-subscriber' });

    const options: RedisOptions = {
      lazyConnect: true,
      maxRetriesPerRequest: null,
      enableReadyCheck: true,
      retryStrategy: (times) => Math.min(RETRY_BASE_MS * 2 ** Math.min(times, 6), RETRY_MAX_MS),
      reconnectOnError: () => true,
    };

    this.client = new Redis(deps.url, options);
    this.wire();
  }

  async connect(): Promise<void> {
    await this.client.connect();
  }

  isHealthy(): boolean {
    return this.ready && this.client.status === 'ready';
  }

  async subscribe(channel: string): Promise<void> {
    await this.client.subscribe(channel);
    this.logger.debug({ event: 'redis.subscribed', channel }, 'subscribed to channel');
  }

  async unsubscribe(channel: string): Promise<void> {
    await this.client.unsubscribe(channel);
    this.logger.debug({ event: 'redis.unsubscribed', channel }, 'unsubscribed from channel');
  }

  async quit(): Promise<void> {
    try {
      await this.client.quit();
    } catch {
      this.client.disconnect();
    }
  }

  private wire(): void {
    this.client.on('ready', () => {
      const first = !this.ready;
      this.ready = true;
      this.logger.info({ event: 'redis.ready' }, 'redis subscriber ready');
      if (!first) void this.restoreChannels();
    });

    this.client.on('error', (error: Error) => {
      this.logger.error({ event: 'redis.error', error: error.message }, 'redis subscriber error');
    });

    this.client.on('close', () => {
      this.ready = false;
      this.logger.warn({ event: 'redis.closed' }, 'redis connection closed');
    });

    this.client.on('reconnecting', (delay: number) => {
      this.logger.warn({ event: 'redis.reconnecting', delay_ms: delay }, 'redis reconnecting');
    });

    this.client.on('message', (channel: string, message: string) => {
      this.handleMessage(channel, message);
    });
  }

  /**
   * After a reconnect ioredis replays its own subscription list, but the hub is
   * the authority on which channels should be live (connections may have come
   * and gone while the socket was down). Re-issuing them is idempotent.
   */
  private async restoreChannels(): Promise<void> {
    const channels = this.deps.activeChannels();
    if (channels.length === 0) return;
    try {
      await this.client.subscribe(...channels);
      this.logger.info({ event: 'redis.channels_restored', count: channels.length }, 'restored channels');
    } catch (cause) {
      this.logger.error(
        { event: 'redis.restore_failed', error: (cause as Error).message },
        'failed to restore channels after reconnect',
      );
    }
  }

  private handleMessage(channel: string, message: string): void {
    let decoded: unknown;
    try {
      decoded = JSON.parse(message);
    } catch {
      eventsReceived.inc({ outcome: 'unparseable' });
      this.logger.warn({ event: 'redis.bad_payload', channel }, 'discarded non-JSON message');
      return;
    }

    const parsed = RealtimeEventSchema.safeParse(decoded);
    if (!parsed.success) {
      eventsReceived.inc({ outcome: 'invalid' });
      this.logger.warn(
        { event: 'redis.schema_mismatch', channel, issues: parsed.error.issues.slice(0, 5) },
        'discarded message failing envelope schema',
      );
      return;
    }

    if (parsed.data.version > ENVELOPE_VERSION) {
      // Forward compatibility: a newer API deployed ahead of this node must not
      // take the fan-out tier down. Drop and count instead.
      eventsReceived.inc({ outcome: 'unsupported_version' });
      this.logger.warn(
        { event: 'redis.unsupported_version', channel, version: parsed.data.version },
        'discarded message from a newer envelope version',
      );
      return;
    }

    eventsReceived.inc({ outcome: 'accepted' });
    this.deps.onEvent(parsed.data);
  }
}
