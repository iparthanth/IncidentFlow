import { generateKeyPairSync } from 'node:crypto';
import { beforeAll, describe, expect, it } from 'vitest';
import request from 'supertest';
import pino from 'pino';
import { SignJWT, importPKCS8 } from 'jose';
import { createApp } from '../src/app.js';
import { loadConfig, type Config } from '../src/config.js';
import { Hub, type ChannelSubscriber } from '../src/hub.js';
import { TicketVerifier } from '../src/auth/jwt.js';

/**
 * The HTTP surface, exercised in process.
 *
 * `hub.test.ts` covers routing logic with the transport mocked out; this covers
 * the transport itself — the auth rejections, the SSE headers, and the probes.
 * Those are exactly the parts that look obviously correct and then fail in
 * production because a header was missing or a probe checked the wrong thing.
 */

const logger = pino({ level: 'silent' });

let config: Config;
// jose v6 returns a CryptoKey from importPKCS8; there is no KeyLike alias.
let privateKey: Awaited<ReturnType<typeof importPKCS8>>;
let app: ReturnType<typeof createApp>;
let redisHealthy = true;

/** In-memory stand-in; this suite is about HTTP, not about Redis. */
class NoopSubscriber implements ChannelSubscriber {
  async subscribe(): Promise<void> {}
  async unsubscribe(): Promise<void> {}
}

async function ticket(
  overrides: { audience?: string; issuer?: string; expiresIn?: string; orgId?: number } = {},
): Promise<string> {
  return new SignJWT({ org_id: overrides.orgId ?? 1, role: 'responder', name: 'Mei Tanaka' })
    .setProtectedHeader({ alg: 'RS256' })
    .setIssuer(overrides.issuer ?? 'incidentflow-api')
    .setAudience(overrides.audience ?? 'incidentflow-realtime')
    .setSubject('3')
    .setJti('test-jti')
    .setIssuedAt()
    .setExpirationTime(overrides.expiresIn ?? '60s')
    .sign(privateKey);
}

beforeAll(async () => {
  const pair = generateKeyPairSync('rsa', {
    modulusLength: 2048,
    publicKeyEncoding: { type: 'spki', format: 'pem' },
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
  });

  privateKey = await importPKCS8(pair.privateKey, 'RS256');

  config = loadConfig({
    NODE_ENV: 'test',
    JWT_PUBLIC_KEY: pair.publicKey,
    REDIS_CHANNEL_PREFIX: 'incidentflow',
    CORS_ORIGINS: 'http://localhost:5173',
  } as NodeJS.ProcessEnv);

  const hub = new Hub(
    new NoopSubscriber(),
    {
      channelPrefix: config.REDIS_CHANNEL_PREFIX,
      maxConnections: config.MAX_CONNECTIONS,
      maxConnectionsPerUser: config.MAX_CONNECTIONS_PER_USER,
      replayBufferSize: config.REPLAY_BUFFER_SIZE,
    },
    logger,
  );

  app = createApp({
    config,
    logger,
    hub,
    verifier: new TicketVerifier(config),
    isReady: () => redisHealthy,
    isShuttingDown: () => false,
  });
});

describe('probes', () => {
  it('liveness never consults a dependency', async () => {
    redisHealthy = false;

    // The whole point: a Redis outage must not make an orchestrator kill and
    // restart every fan-out node, turning a degraded broker into an outage.
    const response = await request(app).get('/healthz').expect(200);
    expect(response.body.status).toBe('ok');

    redisHealthy = true;
  });

  it('readiness fails when Redis is unreachable', async () => {
    redisHealthy = false;
    const down = await request(app).get('/readyz').expect(503);
    expect(down.body.checks.redis).toBe('unavailable');

    redisHealthy = true;
    const up = await request(app).get('/readyz').expect(200);
    expect(up.body.checks.redis).toBe('ok');
  });

  it('exposes Prometheus metrics', async () => {
    const response = await request(app).get('/metrics').expect(200);
    expect(response.headers['content-type']).toContain('text/plain');
    expect(response.text).toContain('incidentflow_realtime_connections');
  });
});

describe('stream authentication', () => {
  it('refuses a request with no credential', async () => {
    const response = await request(app).get('/stream').expect(401);
    expect(response.body.error.code).toBe('ticket_missing');
  });

  it('refuses a malformed token', async () => {
    const response = await request(app).get('/stream?ticket=not-a-jwt').expect(401);
    expect(response.body.error.code).toBe('ticket_malformed');
  });

  it('refuses a token signed by someone else', async () => {
    const impostor = generateKeyPairSync('rsa', {
      modulusLength: 2048,
      publicKeyEncoding: { type: 'spki', format: 'pem' },
      privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    });

    const forged = await new SignJWT({ org_id: 1, role: 'administrator' })
      .setProtectedHeader({ alg: 'RS256' })
      .setIssuer('incidentflow-api')
      .setAudience('incidentflow-realtime')
      .setSubject('3')
      .setJti('forged')
      .setIssuedAt()
      .setExpirationTime('60s')
      .sign(await importPKCS8(impostor.privateKey, 'RS256'));

    // This is the reason for RS256 over a shared secret: holding only the
    // public half, this service can verify but never mint.
    const response = await request(app).get(`/stream?ticket=${forged}`).expect(401);
    expect(response.body.error.code).toBe('ticket_invalid_signature');
  });

  it('refuses an expired ticket', async () => {
    const stale = await ticket({ expiresIn: '-120s' });
    const response = await request(app).get(`/stream?ticket=${stale}`).expect(401);
    expect(response.body.error.code).toBe('ticket_expired');
  });

  it('refuses an API access token replayed as a stream ticket', async () => {
    const apiToken = await ticket({ audience: 'incidentflow-api' });

    // The audience check is what stops a 15-minute REST credential being used
    // in a query string, where it would land in every access log in the path.
    const response = await request(app).get(`/stream?ticket=${apiToken}`).expect(401);
    expect(response.body.error.code).toBe('ticket_wrong_audience');
  });

  it('refuses a token from an unexpected issuer', async () => {
    const foreign = await ticket({ issuer: 'somebody-elses-api' });
    const response = await request(app).get(`/stream?ticket=${foreign}`).expect(401);

    // A correctly-signed token from the wrong issuer is a claim failure, not a
    // parse failure — the distinction matters when reading a log.
    expect(response.body.error.code).toBe('ticket_invalid_claims');
  });
});

describe('stream transport', () => {
  it('opens an event stream with the headers proxies need', async () => {
    const valid = await ticket();

    const response = await request(app)
      .get(`/stream?ticket=${valid}&topics=org:1,incident:42`)
      // supertest waits for the response to end; an SSE stream never does, so
      // the connection is aborted once the opening frames have arrived.
      .buffer(true)
      .parse((res, callback) => {
        let body = '';
        res.on('data', (chunk: Buffer) => {
          body += chunk.toString();
          if (body.includes('stream.open')) (res as unknown as { destroy(): void }).destroy();
        });
        res.on('close', () => callback(null, body));
        res.on('error', () => callback(null, body));
      });

    expect(response.headers['content-type']).toContain('text/event-stream');
    // Without this nginx buffers the stream into silence.
    expect(response.headers['x-accel-buffering']).toBe('no');
    expect(response.headers['cache-control']).toContain('no-cache');

    // `retry:` tells the browser how long to wait before reconnecting.
    expect(response.body).toContain('retry:');
    expect(response.body).toContain('stream.open');
  });

  it('echoes the correlation id so one grep spans all three services', async () => {
    const response = await request(app)
      .get('/healthz')
      .set('X-Request-Id', 'trace-me-4242')
      .expect(200);

    expect(response.headers['x-request-id']).toBe('trace-me-4242');
  });

  it('mints a correlation id when the caller supplies none', async () => {
    const response = await request(app).get('/healthz').expect(200);
    expect(response.headers['x-request-id']).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('answers an unknown route with the shared error envelope', async () => {
    const response = await request(app).get('/nope').expect(404);

    // The same shape the Laravel API uses, so the frontend has one parser.
    expect(response.body.error.code).toBe('not_found');
    expect(response.body.error.request_id).toBeTruthy();
  });
});
