import { readFileSync } from 'node:fs';
import { z } from 'zod';

/**
 * Environment contract for the realtime service.
 *
 * Fail-fast philosophy: the process refuses to boot on invalid configuration
 * rather than discovering it on the first request. An incident-management
 * platform that silently starts with a broken Redis URL is worse than one
 * that never starts.
 */

const csv = (value: string): string[] =>
  value
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean);

const EnvSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  HOST: z.string().default('0.0.0.0'),
  PORT: z.coerce.number().int().min(1).max(65535).default(3001),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']).default('info'),
  SERVICE_NAME: z.string().default('incidentflow-realtime'),

  REDIS_URL: z.string().default('redis://127.0.0.1:6379'),
  REDIS_CHANNEL_PREFIX: z.string().default('incidentflow'),

  /** PEM public key inline. Takes precedence over JWT_PUBLIC_KEY_PATH. */
  JWT_PUBLIC_KEY: z.string().optional(),
  /** Path to a PEM public key on disk (mounted read-only in Docker). */
  JWT_PUBLIC_KEY_PATH: z.string().optional(),
  JWT_ALGORITHM: z.literal('RS256').default('RS256'),
  JWT_ISSUER: z.string().default('incidentflow-api'),
  JWT_AUDIENCE: z.string().default('incidentflow-realtime'),
  /** Clock skew tolerance when validating exp/nbf, in seconds. */
  JWT_CLOCK_TOLERANCE_SECONDS: z.coerce.number().int().min(0).max(300).default(30),

  CORS_ORIGINS: z.string().default('http://localhost:5173'),

  SSE_HEARTBEAT_MS: z.coerce.number().int().min(1_000).max(300_000).default(15_000),
  SSE_RETRY_MS: z.coerce.number().int().min(500).max(60_000).default(3_000),
  /** Events buffered per org for Last-Event-ID replay after a reconnect. */
  REPLAY_BUFFER_SIZE: z.coerce.number().int().min(0).max(10_000).default(200),

  MAX_CONNECTIONS: z.coerce.number().int().min(1).default(10_000),
  MAX_CONNECTIONS_PER_USER: z.coerce.number().int().min(1).default(10),
  /** Max client->server WebSocket frames per minute before the socket is closed. */
  WS_MAX_MESSAGES_PER_MINUTE: z.coerce.number().int().min(1).default(120),

  SHUTDOWN_TIMEOUT_MS: z.coerce.number().int().min(0).default(10_000),
  TRUST_PROXY: z
    .string()
    .default('true')
    .transform((value) => value !== 'false' && value !== '0'),
});

export type RawEnv = z.infer<typeof EnvSchema>;

export interface Config extends Omit<RawEnv, 'CORS_ORIGINS' | 'JWT_PUBLIC_KEY' | 'JWT_PUBLIC_KEY_PATH'> {
  corsOrigins: string[];
  jwtPublicKeyPem: string;
  isProduction: boolean;
  isTest: boolean;
}

/**
 * Accepts a PEM either raw or base64-encoded. Base64 is how the key travels
 * through CI secrets and container env vars without newline mangling.
 */
function normalisePem(value: string): string {
  const trimmed = value.trim();
  if (trimmed.includes('-----BEGIN')) {
    // Env vars often carry literal "\n" instead of real newlines.
    return trimmed.includes('\\n') ? trimmed.replace(/\\n/g, '\n') : trimmed;
  }
  const decoded = Buffer.from(trimmed, 'base64').toString('utf8');
  if (!decoded.includes('-----BEGIN')) {
    throw new Error('JWT public key is neither a PEM nor a base64-encoded PEM');
  }
  return decoded;
}

function resolvePublicKey(env: RawEnv): string {
  if (env.JWT_PUBLIC_KEY && env.JWT_PUBLIC_KEY.trim() !== '') {
    return normalisePem(env.JWT_PUBLIC_KEY);
  }
  if (env.JWT_PUBLIC_KEY_PATH && env.JWT_PUBLIC_KEY_PATH.trim() !== '') {
    return normalisePem(readFileSync(env.JWT_PUBLIC_KEY_PATH, 'utf8'));
  }
  throw new Error('Either JWT_PUBLIC_KEY or JWT_PUBLIC_KEY_PATH must be set');
}

export function loadConfig(source: NodeJS.ProcessEnv = process.env): Config {
  const parsed = EnvSchema.safeParse(source);
  if (!parsed.success) {
    const issues = parsed.error.issues
      .map((issue) => `  - ${issue.path.join('.') || '(root)'}: ${issue.message}`)
      .join('\n');
    throw new Error(`Invalid realtime service configuration:\n${issues}`);
  }

  const env = parsed.data;
  const { CORS_ORIGINS, JWT_PUBLIC_KEY: _pk, JWT_PUBLIC_KEY_PATH: _pkp, ...rest } = env;

  return {
    ...rest,
    corsOrigins: csv(CORS_ORIGINS),
    jwtPublicKeyPem: resolvePublicKey(env),
    isProduction: env.NODE_ENV === 'production',
    isTest: env.NODE_ENV === 'test',
  };
}
