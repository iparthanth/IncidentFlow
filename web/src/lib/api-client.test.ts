import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { z } from 'zod';
import { ApiError, request, setAccessToken, setOrganization } from './api-client';

/**
 * The API client, and the one behaviour in the whole frontend most worth
 * testing: single-flight token refresh.
 *
 * When an access token expires, every query in the app fails at once. Without
 * collapsing those into a single refresh, ten components each fire one; the
 * second to arrive presents a token the server has already rotated, the server
 * correctly treats that as a stolen-token replay, and it revokes the entire
 * family — logging the user out for doing nothing wrong.
 *
 * That failure is invisible in development (where you rarely have ten
 * simultaneous queries and a just-expired token) and reliably reproducible in
 * production. Hence a test.
 */

type FetchArgs = [input: RequestInfo | URL, init?: RequestInit];

function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

const OkSchema = z.object({ data: z.object({ id: z.number() }) });

let calls: FetchArgs[] = [];

function urlOf(call: FetchArgs): string {
  return typeof call[0] === 'string' ? call[0] : String(call[0]);
}

beforeEach(() => {
  calls = [];
  setAccessToken('stale-token');
  setOrganization('northwind');
});

afterEach(() => {
  setAccessToken(null);
  setOrganization(null);
  vi.unstubAllGlobals();
});

describe('token refresh', () => {
  it('collapses concurrent 401s into a single refresh', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (...args: FetchArgs) => {
        calls.push(args);
        const url = urlOf(args);

        if (url.includes('/auth/refresh')) {
          // Deliberately slow, so every caller is still in flight when it lands.
          await new Promise((resolve) => setTimeout(resolve, 20));
          return jsonResponse({ data: { access_token: 'fresh-token' } });
        }

        const token = (args[1]?.headers as Record<string, string>)?.Authorization;
        if (token === 'Bearer stale-token') {
          return jsonResponse({ error: { code: 'token_expired', message: 'expired' } }, 401);
        }

        return jsonResponse({ data: { id: 1 } });
      }),
    );

    // Five components discovering the expiry simultaneously.
    const results = await Promise.all([
      request('/incidents', OkSchema),
      request('/services', OkSchema),
      request('/metrics/summary', OkSchema),
      request('/members', OkSchema),
      request('/postmortems', OkSchema),
    ]);

    expect(results).toHaveLength(5);
    results.forEach((result) => expect(result.data.id).toBe(1));

    const refreshCalls = calls.filter((call) => urlOf(call).includes('/auth/refresh'));
    expect(refreshCalls).toHaveLength(1);
  });

  it('retries the original request once with the new token', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (...args: FetchArgs) => {
        calls.push(args);
        const url = urlOf(args);
        if (url.includes('/auth/refresh')) return jsonResponse({ data: { access_token: 'fresh-token' } });

        const token = (args[1]?.headers as Record<string, string>)?.Authorization;
        return token === 'Bearer stale-token'
          ? jsonResponse({ error: { code: 'token_expired', message: 'expired' } }, 401)
          : jsonResponse({ data: { id: 7 } });
      }),
    );

    const result = await request('/incidents/7', OkSchema);

    expect(result.data.id).toBe(7);
    // Original attempt, refresh, retry — and no further attempts.
    expect(calls).toHaveLength(3);
  });

  it('gives up rather than looping when the refresh itself fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (...args: FetchArgs) => {
        calls.push(args);
        if (urlOf(args).includes('/auth/refresh')) return jsonResponse({}, 401);
        return jsonResponse({ error: { code: 'token_expired', message: 'expired' } }, 401);
      }),
    );

    await expect(request('/incidents', OkSchema)).rejects.toBeInstanceOf(ApiError);

    // A refresh loop here would hammer the API of a system people open
    // *because* something is already broken.
    expect(calls.filter((call) => urlOf(call).includes('/auth/refresh'))).toHaveLength(1);
  });
});

describe('request shaping', () => {
  it('sends booleans as 1 and 0, which is what Laravel accepts', async () => {
    vi.stubGlobal('fetch', vi.fn(async (...args: FetchArgs) => {
      calls.push(args);
      return jsonResponse({ data: { id: 1 } });
    }));

    await request('/incidents', OkSchema, { query: { active_only: true, archived: false } });

    // `String(true)` is "true", which the `boolean` validation rule rejects.
    expect(urlOf(calls[0]!)).toContain('active_only=1');
    expect(urlOf(calls[0]!)).toContain('archived=0');
  });

  it('repeats array parameters the way Laravel parses them', async () => {
    vi.stubGlobal('fetch', vi.fn(async (...args: FetchArgs) => {
      calls.push(args);
      return jsonResponse({ data: { id: 1 } });
    }));

    await request('/incidents', OkSchema, { query: { severity: ['sev1', 'sev2'] } });

    const url = decodeURIComponent(urlOf(calls[0]!));
    expect(url).toContain('severity[]=sev1');
    expect(url).toContain('severity[]=sev2');
  });

  it('carries the tenant header and a correlation id on every request', async () => {
    vi.stubGlobal('fetch', vi.fn(async (...args: FetchArgs) => {
      calls.push(args);
      return jsonResponse({ data: { id: 1 } });
    }));

    await request('/incidents', OkSchema);

    const headers = calls[0]![1]?.headers as Record<string, string>;
    expect(headers['X-Organization']).toBe('northwind');
    expect(headers['X-Request-Id']).toBeTruthy();
  });

  it('reuses one idempotency key across the refresh retry', async () => {
    vi.stubGlobal('fetch', vi.fn(async (...args: FetchArgs) => {
      calls.push(args);
      if (urlOf(args).includes('/auth/refresh')) return jsonResponse({ data: { access_token: 'fresh' } });
      const token = (args[1]?.headers as Record<string, string>)?.Authorization;
      return token === 'Bearer stale-token'
        ? jsonResponse({ error: { code: 'token_expired', message: 'expired' } }, 401)
        : jsonResponse({ data: { id: 1 } }, 201);
    }));

    await request('/incidents', OkSchema, { method: 'POST', body: { title: 'x' } });

    // A fresh key on the retry would defeat the point: the server would treat
    // the retry as a new report and create a second incident.
    const posts = calls.filter((call) => call[1]?.method === 'POST' && !urlOf(call).includes('refresh'));
    const keys = posts.map((call) => (call[1]?.headers as Record<string, string>)['Idempotency-Key']);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
  });
});

describe('error handling', () => {
  it('decodes the shared error envelope', async () => {
    vi.stubGlobal('fetch', vi.fn(async () =>
      jsonResponse(
        {
          error: {
            code: 'incident.illegal_transition',
            message: 'An incident cannot move from Open to Closed.',
            details: { allowed: ['acknowledged', 'resolved'] },
            request_id: 'abc-123',
          },
        },
        422,
      ),
    ));

    const error = await request('/incidents/1/status', OkSchema, { method: 'POST' }).catch((e) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect(error.code).toBe('incident.illegal_transition');
    expect(error.requestId).toBe('abc-123');
    expect(error.isTransient).toBe(false);
  });

  it('flattens validation errors for a form', async () => {
    vi.stubGlobal('fetch', vi.fn(async () =>
      jsonResponse(
        {
          error: {
            code: 'validation_failed',
            message: 'The submitted data failed validation.',
            details: { fields: { title: ['The title must be at least 5 characters.'] } },
          },
        },
        422,
      ),
    ));

    const error: ApiError = await request('/incidents', OkSchema, { method: 'POST' }).catch((e) => e);

    expect(error.isValidation).toBe(true);
    expect(error.fieldErrors()).toEqual({ title: 'The title must be at least 5 characters.' });
  });

  it('treats a contract mismatch as a bug, not a user error', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: { id: 'not-a-number' } })));

    const error: ApiError = await request('/incidents/1', OkSchema).catch((e) => e);

    // Failing loudly at the boundary beats an undefined surfacing three
    // components deep with no clue where it came from.
    expect(error.code).toBe('schema_mismatch');
  });

  it('marks 5xx and 429 as worth retrying, and 4xx as not', async () => {
    for (const [status, transient] of [[500, true], [429, true], [403, false], [404, false]] as const) {
      vi.stubGlobal('fetch', vi.fn(async () =>
        jsonResponse({ error: { code: 'x', message: 'x' } }, status)));

      const error: ApiError = await request('/incidents', OkSchema).catch((e) => e);
      expect(error.isTransient, `status ${status}`).toBe(transient);
    }
  });
});
