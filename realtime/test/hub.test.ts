import { describe, expect, it, beforeEach } from 'vitest';
import pino from 'pino';
import { Hub, isValidTopic, type ChannelSubscriber, type ClientConnection } from '../src/hub.js';
import type { AuthenticatedPrincipal, RealtimeEvent } from '../src/types.js';

const logger = pino({ level: 'silent' });

/** In-memory double, so hub behaviour is testable without a broker. */
class FakeSubscriber implements ChannelSubscriber {
  readonly subscribed = new Set<string>();
  readonly subscribeCalls: string[] = [];
  readonly unsubscribeCalls: string[] = [];

  async subscribe(channel: string): Promise<void> {
    this.subscribed.add(channel);
    this.subscribeCalls.push(channel);
  }

  async unsubscribe(channel: string): Promise<void> {
    this.subscribed.delete(channel);
    this.unsubscribeCalls.push(channel);
  }
}

class FakeConnection implements ClientConnection {
  readonly delivered: RealtimeEvent[] = [];
  readonly controls: Array<{ type: string; payload: Record<string, unknown> }> = [];
  closed: string | null = null;
  failNextDelivery = false;

  constructor(
    readonly id: string,
    readonly principal: AuthenticatedPrincipal,
    readonly transport: 'sse' | 'ws' = 'sse',
  ) {}

  deliver(event: RealtimeEvent): void {
    if (this.failNextDelivery) throw new Error('consumer too slow');
    this.delivered.push(event);
  }

  control(type: string, payload: Record<string, unknown>): void {
    this.controls.push({ type, payload });
  }

  close(reason: string): void {
    this.closed = reason;
  }
}

function principal(userId: string, organizationId: number): AuthenticatedPrincipal {
  return {
    userId,
    organizationId,
    role: 'responder',
    name: 'Test User',
    tokenId: 'jti',
    expiresAt: Math.floor(Date.now() / 1000) + 60,
  };
}

function event(overrides: Partial<RealtimeEvent> = {}): RealtimeEvent {
  return {
    version: 1,
    id: '01JABCDEF0000000000000000',
    type: 'incident.status_changed',
    organization_id: 1,
    incident_id: 42,
    occurred_at: new Date(0).toISOString(),
    actor: { id: 7, name: 'Responder' },
    request_id: 'req-1',
    payload: {},
    ...overrides,
  };
}

describe('Hub', () => {
  let subscriber: FakeSubscriber;
  let hub: Hub;

  beforeEach(() => {
    subscriber = new FakeSubscriber();
    hub = new Hub(
      subscriber,
      { channelPrefix: 'incidentflow', maxConnections: 3, maxConnectionsPerUser: 2, replayBufferSize: 5 },
      logger,
    );
  });

  it('subscribes to an organization channel on the first connection and unsubscribes on the last', async () => {
    const first = new FakeConnection('a', principal('1', 7));
    const second = new FakeConnection('b', principal('2', 7));

    await hub.register(first);
    await hub.register(second);

    // Reference counted: a node subscribes to a channel once, not once per
    // client, and only receives traffic it has a live listener for.
    expect(subscriber.subscribeCalls).toEqual(['incidentflow:org:7']);
    expect(subscriber.subscribed.has('incidentflow:org:7')).toBe(true);

    await hub.unregister('a');
    expect(subscriber.subscribed.has('incidentflow:org:7')).toBe(true);

    await hub.unregister('b');
    expect(subscriber.unsubscribeCalls).toEqual(['incidentflow:org:7']);
  });

  it('delivers an event to every connection in the organization', async () => {
    const a = new FakeConnection('a', principal('1', 7));
    const b = new FakeConnection('b', principal('2', 7));
    await hub.register(a);
    await hub.register(b);

    const delivered = hub.dispatch(event({ organization_id: 7 }));

    expect(delivered).toBe(2);
    expect(a.delivered).toHaveLength(1);
    expect(b.delivered).toHaveLength(1);
  });

  it('never crosses an organization boundary', async () => {
    const ours = new FakeConnection('a', principal('1', 7));
    const theirs = new FakeConnection('b', principal('2', 99));
    await hub.register(ours);
    await hub.register(theirs);

    hub.dispatch(event({ organization_id: 7 }));

    // Defence in depth: the per-org channel should already guarantee this, but
    // a mis-routed publish must not leak one tenant's incident to another.
    expect(ours.delivered).toHaveLength(1);
    expect(theirs.delivered).toHaveLength(0);
  });

  it('filters by incident topic once a client narrows its subscription', async () => {
    const client = new FakeConnection('a', principal('1', 7));
    await hub.register(client);

    hub.updateSubscriptions('a', 'subscribe', ['incident:42']);
    hub.dispatch(event({ organization_id: 7, incident_id: 42 }));
    expect(client.delivered).toHaveLength(1);

    // The org topic is still implicitly subscribed, so an org-wide event for a
    // different incident also arrives — narrowing is additive, not exclusive.
    hub.dispatch(event({ organization_id: 7, incident_id: 999, id: 'other' }));
    expect(client.delivered).toHaveLength(2);
  });

  it('refuses to let a client unsubscribe from its own organization topic', async () => {
    const client = new FakeConnection('a', principal('1', 7));
    await hub.register(client);

    const topics = hub.updateSubscriptions('a', 'unsubscribe', ['org:7']);

    // Otherwise the connection would stay open and receive nothing forever,
    // which looks identical to a broken stream.
    expect(topics).toContain('org:7');
  });

  it('enforces the per-user and the global connection limits separately', async () => {
    // Configured above as maxConnections: 3, maxConnectionsPerUser: 2.
    await hub.register(new FakeConnection('a', principal('1', 7)));
    await hub.register(new FakeConnection('b', principal('1', 7)));

    // One user opening a third tab is refused while the server still has room —
    // this is the limit that stops one client exhausting a shared node.
    await expect(hub.register(new FakeConnection('c', principal('1', 7)))).rejects.toMatchObject({
      name: 'ConnectionLimitError',
      scope: 'per_user',
    });

    // A third distinct user fills the node.
    await hub.register(new FakeConnection('d', principal('2', 7)));

    await expect(hub.register(new FakeConnection('e', principal('3', 7)))).rejects.toMatchObject({
      name: 'ConnectionLimitError',
      scope: 'global',
    });

    expect(hub.connectionCount).toBe(3);
  });

  it('drops a connection that cannot keep up rather than growing the heap', async () => {
    const slow = new FakeConnection('a', principal('1', 7));
    await hub.register(slow);
    slow.failNextDelivery = true;

    hub.dispatch(event({ organization_id: 7 }));

    expect(slow.closed).toBe('delivery_failure');
  });

  it('replays events after a known cursor', async () => {
    const client = new FakeConnection('a', principal('1', 7));
    await hub.register(client);

    hub.dispatch(event({ organization_id: 7, id: 'e1' }));
    hub.dispatch(event({ organization_id: 7, id: 'e2' }));
    hub.dispatch(event({ organization_id: 7, id: 'e3' }));

    const missed = hub.replay('a', 'e1');

    expect(missed?.map((e) => e.id)).toEqual(['e2', 'e3']);
  });

  it('reports a gap rather than silently replaying a partial history', async () => {
    const client = new FakeConnection('a', principal('1', 7));
    await hub.register(client);

    for (let index = 0; index < 8; index++) {
      hub.dispatch(event({ organization_id: 7, id: `e${index}` }));
    }

    // The buffer holds five, so the oldest cursor has aged out. Returning a
    // partial slice would leave a hole in an incident timeline; null tells the
    // caller to refetch from PostgreSQL instead.
    expect(hub.replay('a', 'e0')).toBeNull();
    expect(hub.replay('a', 'e5')?.map((e) => e.id)).toEqual(['e6', 'e7']);
  });

  it('reports its live channels so they can be restored after a reconnect', async () => {
    await hub.register(new FakeConnection('a', principal('1', 7)));
    await hub.register(new FakeConnection('b', principal('2', 8)));

    expect(hub.activeChannels().sort()).toEqual(['incidentflow:org:7', 'incidentflow:org:8']);
  });

  it('closes every connection on shutdown', async () => {
    const a = new FakeConnection('a', principal('1', 7));
    await hub.register(a);

    await hub.closeAll('server_shutdown');

    expect(a.closed).toBe('server_shutdown');
    expect(hub.connectionCount).toBe(0);
    expect(hub.isClosed).toBe(true);
  });
});

describe('isValidTopic', () => {
  it('accepts only the two supported forms', () => {
    expect(isValidTopic('org:1')).toBe(true);
    expect(isValidTopic('incident:4242')).toBe(true);
  });

  it('rejects anything that would make the filter set unbounded or injectable', () => {
    for (const topic of ['org:0', 'org:-1', 'org:*', '*', 'user:1', 'org:', 'org:1;drop', '']) {
      expect(isValidTopic(topic)).toBe(false);
    }
  });
});
