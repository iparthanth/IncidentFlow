import { createServer } from 'node:http';
import { createApp } from './app.js';
import { TicketVerifier } from './auth/jwt.js';
import { loadConfig } from './config.js';
import { Hub } from './hub.js';
import { createLogger } from './logger.js';
import { RedisChannelSubscriber } from './redis.js';
import { attachWebSocketTransport } from './transports/ws.js';

/**
 * Process bootstrap and lifecycle.
 *
 * The shutdown sequence is ordered so that a rolling deploy never drops an
 * event on the floor:
 *   1. flip readiness to 503 so the load balancer stops sending new streams
 *   2. tell connected clients to reconnect (they land on a healthy node)
 *   3. stop accepting HTTP, close Redis, exit
 * A hard timeout guarantees the process dies even if a socket refuses to close.
 */
async function main(): Promise<void> {
  const config = loadConfig();
  const logger = createLogger(config);

  let shuttingDown = false;

  /**
   * The hub and the subscriber reference each other: the subscriber pushes
   * events into the hub, and the hub asks the subscriber to (un)subscribe
   * channels. A tiny holder breaks the cycle without either class needing to
   * know about the other's construction order.
   */
  const wiring: { hub?: Hub } = {};

  const subscriber = new RedisChannelSubscriber({
    url: config.REDIS_URL,
    logger,
    onEvent: (event) => {
      const delivered = wiring.hub?.dispatch(event) ?? 0;
      logger.debug(
        {
          event: 'event.dispatched',
          type: event.type,
          organization_id: event.organization_id,
          incident_id: event.incident_id ?? null,
          request_id: event.request_id ?? null,
          delivered,
        },
        'dispatched event',
      );
    },
    activeChannels: (): string[] => wiring.hub?.activeChannels() ?? [],
  });

  const hub = new Hub(
    subscriber,
    {
      channelPrefix: config.REDIS_CHANNEL_PREFIX,
      maxConnections: config.MAX_CONNECTIONS,
      maxConnectionsPerUser: config.MAX_CONNECTIONS_PER_USER,
      replayBufferSize: config.REPLAY_BUFFER_SIZE,
    },
    logger,
  );

  // Completes the cycle: from here the subscriber can reach the hub.
  wiring.hub = hub;

  const verifier = new TicketVerifier(config);

  const app = createApp({
    config,
    logger,
    hub,
    verifier,
    isReady: () => subscriber.isHealthy(),
    isShuttingDown: () => shuttingDown,
  });

  const server = createServer(app);
  server.keepAliveTimeout = 65_000;
  server.headersTimeout = 70_000;
  // Long-lived SSE streams must never be reaped by the request timeout.
  server.requestTimeout = 0;

  const websockets = attachWebSocketTransport({
    server,
    hub,
    verifier,
    logger,
    path: '/ws',
    heartbeatMs: config.SSE_HEARTBEAT_MS,
    maxMessagesPerMinute: config.WS_MAX_MESSAGES_PER_MINUTE,
  });

  await subscriber.connect();

  await new Promise<void>((resolve) => {
    server.listen(config.PORT, config.HOST, resolve);
  });

  logger.info(
    { event: 'server.started', host: config.HOST, port: config.PORT, node: process.version },
    'realtime service listening',
  );

  const shutdown = async (signal: string): Promise<void> => {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info({ event: 'server.shutdown_started', signal }, 'shutting down');

    const forceExit = setTimeout(() => {
      logger.error({ event: 'server.shutdown_timeout' }, 'forcing exit after shutdown timeout');
      process.exit(1);
    }, config.SHUTDOWN_TIMEOUT_MS);
    forceExit.unref();

    // Give the load balancer a moment to observe the failing readiness probe
    // before we start tearing connections down.
    hub.broadcastControl('stream.reconnect', { reason: 'server_shutdown' });
    await new Promise((resolve) => setTimeout(resolve, Math.min(1_000, config.SHUTDOWN_TIMEOUT_MS)));

    await websockets.close();
    await hub.closeAll('server_shutdown');
    await new Promise<void>((resolve) => server.close(() => resolve()));
    await subscriber.quit();

    clearTimeout(forceExit);
    logger.info({ event: 'server.shutdown_complete' }, 'shutdown complete');
    process.exit(0);
  };

  process.on('SIGTERM', () => void shutdown('SIGTERM'));
  process.on('SIGINT', () => void shutdown('SIGINT'));

  process.on('unhandledRejection', (reason) => {
    logger.error({ event: 'process.unhandled_rejection', reason: String(reason) }, 'unhandled rejection');
  });
  process.on('uncaughtException', (error) => {
    logger.fatal({ event: 'process.uncaught_exception', error: error.message, stack: error.stack }, 'uncaught exception');
    void shutdown('uncaughtException');
  });
}

main().catch((error: unknown) => {
  // Config/bootstrap failures happen before the logger may exist.
  const message = error instanceof Error ? `${error.message}\n${error.stack}` : String(error);
  process.stderr.write(
    `${JSON.stringify({ level: 'fatal', service: 'incidentflow-realtime', message, time: new Date().toISOString() })}\n`,
  );
  process.exit(1);
});
