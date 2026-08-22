import { randomUUID } from 'node:crypto';
import type { NextFunction, Request, RequestHandler, Response } from 'express';
import type { Logger } from 'pino';
import { httpRequestDuration } from './metrics.js';

declare module 'express-serve-static-core' {
  interface Request {
    requestId: string;
    log: Logger;
  }
}

const REQUEST_ID_HEADER = 'x-request-id';

/**
 * Correlation id propagation.
 *
 * The id is minted at the edge (nginx) and travels: browser -> nginx -> Laravel
 * -> Redis envelope -> this service -> back out on the response header. One
 * grep across three services reconstructs a whole user action, which is the
 * entire point of having correlation ids rather than just timestamps.
 */
export function requestId(): RequestHandler {
  return (req: Request, res: Response, next: NextFunction) => {
    const incoming = req.get(REQUEST_ID_HEADER);
    req.requestId = incoming && incoming.length <= 128 ? incoming : randomUUID();
    res.setHeader(REQUEST_ID_HEADER, req.requestId);
    next();
  };
}

export function requestLogger(logger: Logger): RequestHandler {
  return (req: Request, res: Response, next: NextFunction) => {
    req.log = logger.child({ request_id: req.requestId });
    const startedAt = process.hrtime.bigint();

    res.on('finish', () => {
      const seconds = Number(process.hrtime.bigint() - startedAt) / 1e9;
      // `req.route?.path` keeps label cardinality bounded — never the raw URL,
      // which would create one time series per incident id.
      const route = req.route?.path ?? normalisePath(req.path);
      httpRequestDuration.observe(
        { method: req.method, route, status: String(res.statusCode) },
        seconds,
      );

      // Streaming responses finish only on disconnect; logging them at info
      // would make every long-lived SSE stream look like a slow request.
      const level = res.statusCode >= 500 ? 'error' : res.statusCode >= 400 ? 'warn' : 'info';
      req.log[level](
        {
          event: 'http.request',
          method: req.method,
          path: req.path,
          route,
          status: res.statusCode,
          duration_ms: Math.round(seconds * 1000),
          ip: req.ip,
          user_agent: req.get('user-agent'),
        },
        'request completed',
      );
    });

    next();
  };
}

function normalisePath(path: string): string {
  return path.replace(/\/\d+(?=\/|$)/g, '/:id');
}

/**
 * Minimal CORS. Only needed in development, where Vite serves the SPA from a
 * different origin; in production nginx puts everything behind one origin and
 * no preflight ever happens.
 */
export function cors(allowedOrigins: string[]): RequestHandler {
  const allowAll = allowedOrigins.includes('*');
  return (req: Request, res: Response, next: NextFunction) => {
    const origin = req.get('origin');
    if (origin && (allowAll || allowedOrigins.includes(origin))) {
      res.setHeader('Access-Control-Allow-Origin', origin);
      res.setHeader('Vary', 'Origin');
      res.setHeader('Access-Control-Allow-Credentials', 'true');
      res.setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Last-Event-ID, X-Request-Id');
      res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
      res.setHeader('Access-Control-Max-Age', '600');
    }
    if (req.method === 'OPTIONS') {
      res.status(204).end();
      return;
    }
    next();
  };
}

export function securityHeaders(): RequestHandler {
  return (_req: Request, res: Response, next: NextFunction) => {
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.removeHeader('X-Powered-By');
    next();
  };
}

export class HttpError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details?: Record<string, unknown>,
  ) {
    super(message);
    this.name = 'HttpError';
  }
}

/**
 * Terminal error handler. Emits the same envelope shape as the Laravel API so
 * the frontend has exactly one error contract to parse.
 */
export function errorHandler(isProduction: boolean): (
  error: unknown,
  req: Request,
  res: Response,
  next: NextFunction,
) => void {
  return (error: unknown, req: Request, res: Response, next: NextFunction) => {
    if (res.headersSent) {
      // Mid-stream failure: the only honest thing left is to end the response.
      next(error);
      return;
    }

    const http = error instanceof HttpError ? error : null;
    const status = http?.status ?? 500;
    const code = http?.code ?? 'internal_error';
    const message = http ? http.message : isProduction ? 'An unexpected error occurred' : String(error);

    if (status >= 500) {
      req.log?.error(
        { event: 'http.error', error: error instanceof Error ? error.message : String(error), stack: error instanceof Error ? error.stack : undefined },
        'unhandled error',
      );
    }

    res.status(status).json({
      error: {
        code,
        message,
        details: http?.details ?? null,
        request_id: req.requestId,
      },
    });
  };
}
