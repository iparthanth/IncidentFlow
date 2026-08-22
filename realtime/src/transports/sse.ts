import type { Request, Response } from 'express';
import type { Logger } from 'pino';
import type { ClientConnection } from '../hub.js';
import type { AuthenticatedPrincipal, RealtimeEvent } from '../types.js';

export interface SseOptions {
  heartbeatMs: number;
  retryMs: number;
}

/**
 * Server-Sent Events connection.
 *
 * SSE is the primary browser transport because the data flow here is strictly
 * server -> client: an incident timeline is published, never negotiated. SSE
 * gets automatic reconnection with `Last-Event-ID` replay for free, survives
 * HTTP/1.1 proxies, and needs no protocol upgrade — the WebSocket transport
 * exists alongside it for non-browser consumers that want a duplex channel.
 *
 * Two proxy hazards are handled explicitly:
 *  - `X-Accel-Buffering: no` stops nginx from buffering the stream into silence.
 *  - a periodic comment heartbeat keeps idle-connection reapers from closing it.
 */
export class SseConnection implements ClientConnection {
  readonly transport = 'sse' as const;
  private heartbeat: NodeJS.Timeout | undefined;
  private closed = false;

  constructor(
    readonly id: string,
    readonly principal: AuthenticatedPrincipal,
    private readonly response: Response,
    private readonly options: SseOptions,
    private readonly logger: Logger,
  ) {}

  start(): void {
    this.response.writeHead(200, {
      'Content-Type': 'text/event-stream; charset=utf-8',
      'Cache-Control': 'no-cache, no-store, no-transform',
      Connection: 'keep-alive',
      'X-Accel-Buffering': 'no',
    });

    // Tells the browser how long to wait before reconnecting after a drop.
    this.write(`retry: ${this.options.retryMs}\n\n`);
    this.control('stream.open', {
      connection_id: this.id,
      organization_id: this.principal.organizationId,
      heartbeat_ms: this.options.heartbeatMs,
    });

    this.heartbeat = setInterval(() => {
      // A comment frame: valid SSE, ignored by EventSource, but enough traffic
      // to keep every intermediary convinced the connection is alive.
      this.write(`: heartbeat ${Date.now()}\n\n`);
    }, this.options.heartbeatMs);
    this.heartbeat.unref?.();
  }

  deliver(event: RealtimeEvent): void {
    this.write(`id: ${event.id}\nevent: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`);
  }

  control(type: string, payload: Record<string, unknown>): void {
    this.write(`event: ${type}\ndata: ${JSON.stringify({ type, ...payload })}\n\n`);
  }

  close(reason: string): void {
    if (this.closed) return;
    this.closed = true;
    if (this.heartbeat) clearInterval(this.heartbeat);
    try {
      this.response.end();
    } catch (cause) {
      this.logger.debug(
        { event: 'sse.close_failed', connection_id: this.id, reason, error: (cause as Error).message },
        'error while closing SSE response',
      );
    }
  }

  get isClosed(): boolean {
    return this.closed;
  }

  private write(chunk: string): void {
    if (this.closed) return;
    const flushed = this.response.write(chunk);
    if (!flushed) {
      // Backpressure: the socket buffer is full. For a live incident feed a
      // slow consumer is better dropped than allowed to grow the heap of a
      // shared fan-out node.
      const socket = this.response.socket;
      const buffered = socket?.writableLength ?? 0;
      if (buffered > MAX_BUFFERED_BYTES) {
        throw new Error(`SSE consumer too slow: ${buffered} bytes buffered`);
      }
    }
  }
}

const MAX_BUFFERED_BYTES = 1_048_576; // 1 MiB

/** Parses the reconnect cursor a browser sends after a dropped stream. */
export function lastEventIdFrom(request: Request): string | null {
  const header = request.get('last-event-id');
  if (header && header.trim() !== '') return header.trim();
  const query = request.query['last_event_id'];
  return typeof query === 'string' && query.trim() !== '' ? query.trim() : null;
}
