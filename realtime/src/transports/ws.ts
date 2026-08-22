import type { IncomingMessage, Server as HttpServer } from 'node:http';
import type { Duplex } from 'node:stream';
import { WebSocketServer, type WebSocket } from 'ws';
import type { Logger } from 'pino';
import { ClientCommandSchema, type AuthenticatedPrincipal, type RealtimeEvent, type Topic } from '../types.js';
import { ConnectionLimitError, Hub, isValidTopic, newConnectionId, type ClientConnection } from '../hub.js';
import { AuthError, TicketVerifier, extractCredential } from '../auth/jwt.js';
import { connectionsRejected } from '../metrics.js';

const MAX_BUFFERED_BYTES = 1_048_576; // 1 MiB
const MAX_FRAME_BYTES = 16 * 1024;

export class WsConnection implements ClientConnection {
  readonly transport = 'ws' as const;
  private closed = false;

  constructor(
    readonly id: string,
    readonly principal: AuthenticatedPrincipal,
    private readonly socket: WebSocket,
    private readonly logger: Logger,
  ) {}

  deliver(event: RealtimeEvent): void {
    this.send({ kind: 'event', event });
  }

  control(type: string, payload: Record<string, unknown>): void {
    this.send({ kind: 'control', type, ...payload });
  }

  close(reason: string): void {
    if (this.closed) return;
    this.closed = true;
    try {
      this.socket.close(1000, reason.slice(0, 120));
    } catch (cause) {
      this.logger.debug(
        { event: 'ws.close_failed', connection_id: this.id, error: (cause as Error).message },
        'error while closing websocket',
      );
      this.socket.terminate();
    }
  }

  private send(message: Record<string, unknown>): void {
    if (this.closed || this.socket.readyState !== this.socket.OPEN) return;
    if (this.socket.bufferedAmount > MAX_BUFFERED_BYTES) {
      throw new Error(`WebSocket consumer too slow: ${this.socket.bufferedAmount} bytes buffered`);
    }
    this.socket.send(JSON.stringify(message));
  }
}

export interface WebSocketTransportDeps {
  server: HttpServer;
  hub: Hub;
  verifier: TicketVerifier;
  logger: Logger;
  path: string;
  heartbeatMs: number;
  maxMessagesPerMinute: number;
}

/**
 * WebSocket transport.
 *
 * Authentication happens during the HTTP upgrade, *before* the socket exists —
 * an unauthenticated peer never gets a live WebSocket to send frames on. A
 * ping/pong liveness probe reaps half-open sockets, which plain TCP keepalive
 * will not detect for hours behind a NAT.
 */
export function attachWebSocketTransport(deps: WebSocketTransportDeps): { close: () => Promise<void> } {
  const { server, hub, verifier, logger, path } = deps;
  const wss = new WebSocketServer({ noServer: true, maxPayload: MAX_FRAME_BYTES });
  const alive = new WeakMap<WebSocket, boolean>();

  server.on('upgrade', (request: IncomingMessage, socket: Duplex, head: Buffer) => {
    const url = new URL(request.url ?? '/', 'http://localhost');
    if (url.pathname !== path) return; // another handler may own this path

    void (async () => {
      try {
        const query = Object.fromEntries(url.searchParams.entries());
        const credential = extractCredential(request.headers as Record<string, string | string[] | undefined>, query);
        if (!credential) throw new AuthError('No credential supplied', 'missing');

        const principal = await verifier.verify(credential);
        wss.handleUpgrade(request, socket, head, (ws) => {
          void onConnection(ws, principal, url);
        });
      } catch (cause) {
        const reason = cause instanceof AuthError ? cause.reason : 'error';
        connectionsRejected.inc({ transport: 'ws', reason });
        logger.warn({ event: 'ws.upgrade_rejected', reason }, 'rejected websocket upgrade');
        socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
        socket.destroy();
      }
    })();
  });

  async function onConnection(ws: WebSocket, principal: AuthenticatedPrincipal, url: URL): Promise<void> {
    const connectionId = newConnectionId();
    const connection = new WsConnection(connectionId, principal, ws, logger);
    const requested = (url.searchParams.get('topics') ?? '')
      .split(',')
      .map((topic) => topic.trim())
      .filter(isValidTopic) as Topic[];

    try {
      await hub.register(connection, requested);
    } catch (cause) {
      const code = cause instanceof ConnectionLimitError ? 1013 : 1011;
      ws.close(code, cause instanceof Error ? cause.message.slice(0, 120) : 'registration failed');
      return;
    }

    alive.set(ws, true);
    connection.control('stream.open', { connection_id: connectionId, organization_id: principal.organizationId });

    // Sliding-ish window: a simple fixed window is enough to stop a client
    // hammering subscribe/unsubscribe, and costs one integer per socket.
    let windowStart = Date.now();
    let framesInWindow = 0;

    ws.on('pong', () => alive.set(ws, true));

    ws.on('message', (raw) => {
      const now = Date.now();
      if (now - windowStart >= 60_000) {
        windowStart = now;
        framesInWindow = 0;
      }
      framesInWindow += 1;
      if (framesInWindow > deps.maxMessagesPerMinute) {
        logger.warn({ event: 'ws.rate_limited', connection_id: connectionId }, 'closing chatty websocket');
        ws.close(1008, 'rate limit exceeded');
        return;
      }

      let decoded: unknown;
      try {
        decoded = JSON.parse(raw.toString());
      } catch {
        connection.control('error', { code: 'invalid_json', message: 'Frames must be JSON objects' });
        return;
      }

      const command = ClientCommandSchema.safeParse(decoded);
      if (!command.success) {
        connection.control('error', { code: 'invalid_command', message: 'Unrecognised command' });
        return;
      }

      switch (command.data.action) {
        case 'subscribe':
        case 'unsubscribe': {
          const topics = hub.updateSubscriptions(connectionId, command.data.action, command.data.topics);
          connection.control('subscriptions', { topics });
          break;
        }
        case 'ping':
          connection.control('pong', { at: new Date().toISOString() });
          break;
      }
    });

    const onGone = () => {
      void hub.unregister(connectionId);
    };
    ws.on('close', onGone);
    ws.on('error', (error) => {
      logger.debug({ event: 'ws.error', connection_id: connectionId, error: error.message }, 'websocket error');
      onGone();
    });
  }

  const heartbeat = setInterval(() => {
    for (const ws of wss.clients) {
      if (alive.get(ws) === false) {
        ws.terminate(); // half-open: peer vanished without a FIN
        continue;
      }
      alive.set(ws, false);
      try {
        ws.ping();
      } catch {
        ws.terminate();
      }
    }
  }, deps.heartbeatMs);
  heartbeat.unref?.();

  return {
    close: async () => {
      clearInterval(heartbeat);
      for (const ws of wss.clients) ws.close(1001, 'server shutting down');
      await new Promise<void>((resolve) => wss.close(() => resolve()));
    },
  };
}
