import pino, { type Logger } from 'pino';
import type { Config } from './config.js';

/**
 * Structured JSON logs on stdout. No file rotation, no transports in
 * production: the container runtime owns log shipping. Pretty-printing is
 * deliberately absent so dev and prod emit byte-identical shapes.
 */
export function createLogger(config: Pick<Config, 'LOG_LEVEL' | 'SERVICE_NAME' | 'NODE_ENV'>): Logger {
  return pino({
    level: config.LOG_LEVEL,
    base: {
      service: config.SERVICE_NAME,
      env: config.NODE_ENV,
      pid: process.pid,
    },
    timestamp: pino.stdTimeFunctions.isoTime,
    messageKey: 'message',
    formatters: {
      level: (label) => ({ level: label }),
    },
    redact: {
      paths: [
        'req.headers.authorization',
        'req.headers.cookie',
        'token',
        'ticket',
        '*.password',
        '*.refresh_token',
      ],
      censor: '[redacted]',
    },
  });
}

export type { Logger };
