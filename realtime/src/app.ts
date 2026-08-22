import express, { type Express, type Request, type Response } from 'express';
import type { Logger } from 'pino';
import type { Config } from './config.js';
import { ConnectionLimitError, Hub, isValidTopic, newConnectionId } from './hub.js';
import { AuthError, TicketVerifier, extractCredential } from './auth/jwt.js';
import { SseConnection, lastEventIdFrom } from './transports/sse.js';
import { registry } from './metrics.js';
import { HttpError, cors, errorHandler, requestId, requestLogger, securityHeaders } from './middleware.js';
import type { Topic } from './types.js';

export interface AppDeps {
  config: Config;
  logger: Logger;
  hub: Hub;
  verifier: TicketVerifier;
  /** Downstream dependencies are usable (Redis connected). */
  isReady: () => boolean;
  /** Process has begun draining; readiness must fail so the LB stops routing. */
  isShuttingDown: () => boolean;
}

export function createApp(deps: AppDeps): Express {
  // `verifier` is reached through `deps` in the stream handler rather than
  // destructured here, so it stays out of this scope.
  const { config, logger, hub } = deps;
  const app = express();

  app.set('trust proxy', config.TRUST_PROXY);
  app.disable('x-powered-by');
  app.disable('etag');

  app.use(requestId());
  app.use(securityHeaders());
  app.use(cors(config.corsOrigins));
  app.use(requestLogger(logger));

  /**
   * Liveness: "is the process wedged?" Deliberately dependency-free — a Redis
   * outage must not cause Kubernetes to kill and restart every realtime pod,
   * which would turn a degraded broker into a full outage.
   */
  app.get('/healthz', (_req: Request, res: Response) => {
    res.json({
      status: 'ok',
      service: config.SERVICE_NAME,
      uptime_seconds: Math.round(process.uptime()),
    });
  });

  /**
   * Readiness: "should traffic be sent here?" This one *does* check Redis,
   * because a node that cannot receive events would accept streams and then
   * deliver silence.
   */
  app.get('/readyz', (_req: Request, res: Response) => {
    const draining = deps.isShuttingDown();
    const redisReady = deps.isReady();
    const ready = redisReady && !draining;

    res.status(ready ? 200 : 503).json({
      status: ready ? 'ready' : 'not_ready',
      checks: {
        redis: redisReady ? 'ok' : 'unavailable',
        draining,
      },
      connections: hub.connectionCount,
    });
  });

  app.get('/metrics', async (_req: Request, res: Response) => {
    res.setHeader('Content-Type', registry.contentType);
    res.send(await registry.metrics());
  });

  app.get('/stream', (req, res, next) => {
    void handleStream(req, res, deps).catch(next);
  });

  app.use((req: Request, _res: Response, next) => {
    next(new HttpError(404, 'not_found', `No route for ${req.method} ${req.path}`));
  });

  app.use(errorHandler(config.isProduction));

  return app;
}

async function handleStream(req: Request, res: Response, deps: AppDeps): Promise<void> {
  const { config, hub, verifier } = deps;

  if (deps.isShuttingDown()) {
    throw new HttpError(503, 'draining', 'This node is shutting down; retry against another node');
  }

  const credential = extractCredential(
    req.headers as Record<string, string | string[] | undefined>,
    req.query as Record<string, unknown>,
  );

  let principal;
  try {
    if (!credential) throw new AuthError('No ticket supplied', 'missing');
    principal = await verifier.verify(credential);
  } catch (cause) {
    if (cause instanceof AuthError) {
      throw new HttpError(401, `ticket_${cause.reason}`, cause.message);
    }
    throw cause;
  }

  const topics = parseTopics(req.query['topics']);
  const connection = new SseConnection(
    newConnectionId(),
    principal,
    res,
    { heartbeatMs: config.SSE_HEARTBEAT_MS, retryMs: config.SSE_RETRY_MS },
    req.log,
  );

  try {
    await hub.register(connection, topics);
  } catch (cause) {
    if (cause instanceof ConnectionLimitError) {
      res.setHeader('Retry-After', '5');
      throw new HttpError(503, 'connection_limit', cause.message, { scope: cause.scope });
    }
    throw cause;
  }

  connection.start();

  /**
   * Reconnect handling. The browser resends the id of the last event it saw;
   * anything newer still in the ring buffer is replayed. If the cursor has
   * already aged out we say so explicitly — the client then refetches from
   * PostgreSQL, which is the source of truth. Pretending the stream is
   * continuous when it is not would leave holes in an incident timeline.
   */
  const lastEventId = lastEventIdFrom(req);
  if (lastEventId) {
    const missed = hub.replay(connection.id, lastEventId);
    if (missed === null) {
      connection.control('stream.gap', {
        last_event_id: lastEventId,
        message: 'Cursor is outside the replay window; refetch current state from the API',
      });
    } else {
      for (const event of missed) connection.deliver(event);
      connection.control('stream.replayed', { count: missed.length, last_event_id: lastEventId });
    }
  }

  const teardown = (): void => {
    connection.close('client_disconnected');
    void hub.unregister(connection.id);
  };
  req.on('close', teardown);
  res.on('error', teardown);
}

function parseTopics(raw: unknown): Topic[] {
  if (typeof raw !== 'string' || raw.trim() === '') return [];
  return raw
    .split(',')
    .map((topic) => topic.trim())
    .filter(isValidTopic)
    .slice(0, 50) as Topic[];
}
