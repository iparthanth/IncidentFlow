import { randomUUID } from 'node:crypto';
import type { Logger } from 'pino';
import {
  type AuthenticatedPrincipal,
  type RealtimeEvent,
  type Topic,
  orgTopic,
  topicsForEvent,
} from './types.js';
import {
  connectionsGauge,
  connectionsRejected,
  connectionsTotal,
  deliveryFailures,
  eventsDelivered,
  redisChannelsGauge,
  subscriptionsGauge,
} from './metrics.js';

export type Transport = 'sse' | 'ws';

export interface ClientConnection {
  readonly id: string;
  readonly transport: Transport;
  readonly principal: AuthenticatedPrincipal;
  /** Deliver one event. Throwing marks the connection dead. */
  deliver(event: RealtimeEvent): void;
  /** Deliver an out-of-band control frame (gap notice, shutdown, …). */
  control(type: string, payload: Record<string, unknown>): void;
  close(reason: string): void;
}

/**
 * The channel abstraction the hub drives. Redis implements it in production;
 * tests inject an in-memory double so hub behaviour is verifiable without a
 * running broker.
 */
export interface ChannelSubscriber {
  subscribe(channel: string): Promise<void>;
  unsubscribe(channel: string): Promise<void>;
}

export interface HubOptions {
  channelPrefix: string;
  maxConnections: number;
  maxConnectionsPerUser: number;
  replayBufferSize: number;
}

export class ConnectionLimitError extends Error {
  constructor(readonly scope: 'global' | 'per_user') {
    super(scope === 'global' ? 'Server connection limit reached' : 'Too many connections for this user');
    this.name = 'ConnectionLimitError';
  }
}

interface Registration {
  client: ClientConnection;
  topics: Set<string>;
}

/**
 * Fan-out core.
 *
 * Redis channels are subscribed per organization and reference-counted, so a
 * node only receives traffic it has a live listener for. That is what allows
 * the realtime tier to scale horizontally: adding a node does not multiply the
 * broker traffic for organizations that node serves nobody for.
 */
export class Hub {
  private readonly clients = new Map<string, Registration>();
  private readonly byOrganization = new Map<number, Set<string>>();
  private readonly byUser = new Map<string, Set<string>>();
  private readonly channelRefCounts = new Map<number, number>();
  /** organizationId -> recent events, oldest first, for Last-Event-ID replay. */
  private readonly replayBuffers = new Map<number, RealtimeEvent[]>();
  private closed = false;

  constructor(
    private readonly subscriber: ChannelSubscriber,
    private readonly options: HubOptions,
    private readonly logger: Logger,
  ) {}

  channelFor(organizationId: number): string {
    return `${this.options.channelPrefix}:org:${organizationId}`;
  }

  /** Channels with a live reference count — used to restore state after a Redis reconnect. */
  activeChannels(): string[] {
    return [...this.channelRefCounts.keys()].map((orgId) => this.channelFor(orgId));
  }

  get connectionCount(): number {
    return this.clients.size;
  }

  async register(client: ClientConnection, initialTopics: Topic[] = []): Promise<void> {
    if (this.clients.size >= this.options.maxConnections) {
      connectionsRejected.inc({ transport: client.transport, reason: 'server_limit' });
      throw new ConnectionLimitError('global');
    }
    const userConnections = this.byUser.get(client.principal.userId);
    if (userConnections && userConnections.size >= this.options.maxConnectionsPerUser) {
      connectionsRejected.inc({ transport: client.transport, reason: 'user_limit' });
      throw new ConnectionLimitError('per_user');
    }

    const registration: Registration = { client, topics: new Set<string>() };
    this.clients.set(client.id, registration);

    index(this.byOrganization, client.principal.organizationId, client.id);
    index(this.byUser, client.principal.userId, client.id);

    // Every client is implicitly subscribed to its own organization topic; the
    // org channel is the only source of events it can ever receive anyway.
    this.addTopics(registration, [orgTopic(client.principal.organizationId), ...initialTopics]);

    await this.acquireChannel(client.principal.organizationId);

    connectionsTotal.inc({ transport: client.transport });
    connectionsGauge.inc({ transport: client.transport });
    this.logger.info(
      {
        event: 'connection.opened',
        connection_id: client.id,
        transport: client.transport,
        user_id: client.principal.userId,
        organization_id: client.principal.organizationId,
        connections: this.clients.size,
      },
      'client connected',
    );
  }

  async unregister(clientId: string): Promise<void> {
    const registration = this.clients.get(clientId);
    if (!registration) return;

    const { client } = registration;
    this.clients.delete(clientId);
    subscriptionsGauge.dec(registration.topics.size);
    deindex(this.byOrganization, client.principal.organizationId, clientId);
    deindex(this.byUser, client.principal.userId, clientId);
    connectionsGauge.dec({ transport: client.transport });

    await this.releaseChannel(client.principal.organizationId);

    this.logger.info(
      {
        event: 'connection.closed',
        connection_id: clientId,
        transport: client.transport,
        user_id: client.principal.userId,
        organization_id: client.principal.organizationId,
        connections: this.clients.size,
      },
      'client disconnected',
    );
  }

  /**
   * Narrows what a connection receives. Topics are filters over an already
   * org-scoped stream, so an unauthorised-looking topic simply never matches.
   * Malformed topics are rejected outright to keep the filter set bounded.
   */
  updateSubscriptions(clientId: string, action: 'subscribe' | 'unsubscribe', topics: string[]): string[] {
    const registration = this.clients.get(clientId);
    if (!registration) return [];

    const valid = topics.filter(isValidTopic);
    if (action === 'subscribe') {
      this.addTopics(registration, valid as Topic[]);
    } else {
      for (const topic of valid) {
        if (topic === orgTopic(registration.client.principal.organizationId)) {
          // The org topic is structural; unsubscribing from it would leave a
          // connection that can never receive anything.
          continue;
        }
        if (registration.topics.delete(topic)) subscriptionsGauge.dec();
      }
    }
    return [...registration.topics];
  }

  /** Routes one event to every connection whose filters match. */
  dispatch(event: RealtimeEvent): number {
    const candidates = this.byOrganization.get(event.organization_id);
    this.remember(event);
    if (!candidates || candidates.size === 0) return 0;

    const eventTopics = topicsForEvent(event);
    let delivered = 0;

    for (const clientId of [...candidates]) {
      const registration = this.clients.get(clientId);
      if (!registration) continue;

      // Defence in depth: the org channel should already guarantee this, but a
      // mis-routed publish must never cross an organization boundary.
      if (registration.client.principal.organizationId !== event.organization_id) continue;
      if (!eventTopics.some((topic) => registration.topics.has(topic))) continue;

      try {
        registration.client.deliver(event);
        eventsDelivered.inc({ transport: registration.client.transport });
        delivered += 1;
      } catch (cause) {
        deliveryFailures.inc({ transport: registration.client.transport, reason: 'write_error' });
        this.logger.warn(
          {
            event: 'delivery.failed',
            connection_id: clientId,
            error: (cause as Error).message,
          },
          'dropping connection after delivery failure',
        );
        registration.client.close('delivery_failure');
        void this.unregister(clientId);
      }
    }

    return delivered;
  }

  /**
   * Replays buffered events after `lastEventId` for a reconnecting client.
   *
   * Returns `null` when the id is no longer in the buffer — the caller then
   * tells the client to refetch. Silently sending a partial history would be
   * worse than admitting the gap: an incident timeline with a hole in it is a
   * correctness bug, not a cosmetic one.
   */
  replay(clientId: string, lastEventId: string): RealtimeEvent[] | null {
    const registration = this.clients.get(clientId);
    if (!registration) return [];

    const buffer = this.replayBuffers.get(registration.client.principal.organizationId) ?? [];
    const index = buffer.findIndex((event) => event.id === lastEventId);
    if (index === -1) return null;

    return buffer.slice(index + 1).filter((event) => {
      const topics = topicsForEvent(event);
      return topics.some((topic) => registration.topics.has(topic));
    });
  }

  /** Broadcasts a control frame to every connection, e.g. on shutdown. */
  broadcastControl(type: string, payload: Record<string, unknown> = {}): void {
    for (const { client } of this.clients.values()) {
      try {
        client.control(type, payload);
      } catch {
        /* connection is going away regardless */
      }
    }
  }

  async closeAll(reason: string): Promise<void> {
    this.closed = true;
    for (const clientId of [...this.clients.keys()]) {
      const registration = this.clients.get(clientId);
      registration?.client.close(reason);
      await this.unregister(clientId);
    }
  }

  get isClosed(): boolean {
    return this.closed;
  }

  private addTopics(registration: Registration, topics: Topic[]): void {
    for (const topic of topics) {
      if (!registration.topics.has(topic)) {
        registration.topics.add(topic);
        subscriptionsGauge.inc();
      }
    }
  }

  private remember(event: RealtimeEvent): void {
    if (this.options.replayBufferSize === 0) return;
    const buffer = this.replayBuffers.get(event.organization_id) ?? [];
    buffer.push(event);
    if (buffer.length > this.options.replayBufferSize) {
      buffer.splice(0, buffer.length - this.options.replayBufferSize);
    }
    this.replayBuffers.set(event.organization_id, buffer);
  }

  private async acquireChannel(organizationId: number): Promise<void> {
    const current = this.channelRefCounts.get(organizationId) ?? 0;
    this.channelRefCounts.set(organizationId, current + 1);
    redisChannelsGauge.set(this.channelRefCounts.size);
    if (current === 0) {
      await this.subscriber.subscribe(this.channelFor(organizationId));
    }
  }

  private async releaseChannel(organizationId: number): Promise<void> {
    const current = this.channelRefCounts.get(organizationId) ?? 0;
    if (current <= 1) {
      this.channelRefCounts.delete(organizationId);
      this.replayBuffers.delete(organizationId);
      redisChannelsGauge.set(this.channelRefCounts.size);
      await this.subscriber.unsubscribe(this.channelFor(organizationId));
      return;
    }
    this.channelRefCounts.set(organizationId, current - 1);
  }
}

const TOPIC_PATTERN = /^(org|incident):[1-9][0-9]{0,17}$/;

export function isValidTopic(topic: string): topic is Topic {
  return TOPIC_PATTERN.test(topic);
}

export function newConnectionId(): string {
  return randomUUID();
}

/** Adds a connection id to a bucketed index (by organization or by user). */
function index<K>(map: Map<K, Set<string>>, key: K, value: string): void {
  const bucket = map.get(key) ?? new Set<string>();
  bucket.add(value);
  map.set(key, bucket);
}

/** Removes it, dropping the bucket entirely once empty so the maps stay bounded. */
function deindex<K>(map: Map<K, Set<string>>, key: K, value: string): void {
  const bucket = map.get(key);
  if (!bucket) return;
  bucket.delete(value);
  if (bucket.size === 0) map.delete(key);
}
