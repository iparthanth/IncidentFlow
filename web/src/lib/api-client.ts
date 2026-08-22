import type { z } from 'zod';
import { ErrorEnvelopeSchema } from './schemas';

/**
 * The single door to the API.
 *
 * Everything that must happen on *every* request lives here rather than being
 * re-implemented per call site: bearer auth, the organization header, a
 * correlation id, idempotency keys on unsafe methods, error-envelope decoding,
 * and — the interesting one — transparent token refresh.
 */

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: unknown = null,
    readonly requestId: string | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Worth showing a "retry" affordance for. */
  get isTransient(): boolean {
    return this.status >= 500 || this.status === 429;
  }

  get isValidation(): boolean {
    return this.status === 422 && this.code === 'validation_failed';
  }

  /** Field errors from a 422, shaped for react-hook-form. */
  fieldErrors(): Record<string, string> {
    if (!this.isValidation) return {};
    const fields = (this.details as { fields?: Record<string, string[]> } | null)?.fields ?? {};
    return Object.fromEntries(
      Object.entries(fields).map(([field, messages]) => [field, messages[0] ?? 'Invalid value']),
    );
  }
}

type TokenListener = (token: string | null) => void;

/**
 * Access-token storage.
 *
 * In a module-level variable, deliberately — not localStorage, not
 * sessionStorage. Anything an XSS payload can read, it will exfiltrate; a
 * closure variable dies with the tab. The long-lived refresh token lives in an
 * HttpOnly cookie the page cannot read at all, so the worst an injected script
 * can do is act *during* its own page's lifetime rather than walk away with a
 * 30-day session.
 */
let accessToken: string | null = null;
let currentOrganization: string | null = null;
const listeners = new Set<TokenListener>();

export function setAccessToken(token: string | null): void {
  accessToken = token;
  listeners.forEach((listener) => listener(token));
}

export function getAccessToken(): string | null {
  return accessToken;
}

export function onTokenChange(listener: TokenListener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function setOrganization(slugOrId: string | null): void {
  currentOrganization = slugOrId;
}

export function getOrganization(): string | null {
  return currentOrganization;
}

const API_BASE = '/api/v1';

/**
 * In-flight refresh, shared.
 *
 * When an access token expires, every query in the app fails at once. Without
 * this, ten components would each fire a refresh; the second one to arrive
 * would present an already-rotated token, and the server — correctly — would
 * treat that as token reuse and revoke the whole family, logging the user out
 * for doing nothing wrong. Collapsing them into one promise is what makes
 * rotation and concurrency coexist.
 */
let refreshInFlight: Promise<string | null> | null = null;

async function refreshAccessToken(): Promise<string | null> {
  refreshInFlight ??= (async () => {
    try {
      const response = await fetch(`${API_BASE}/auth/refresh`, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        // The refresh token rides in the HttpOnly cookie; nothing to send.
        credentials: 'same-origin',
      });

      if (!response.ok) {
        setAccessToken(null);
        return null;
      }

      const body = (await response.json()) as { data?: { access_token?: string } };
      const token = body.data?.access_token ?? null;
      setAccessToken(token);
      return token;
    } catch {
      setAccessToken(null);
      return null;
    } finally {
      // Cleared in a microtask so concurrent callers awaiting the same promise
      // all observe the result before the slot reopens.
      queueMicrotask(() => {
        refreshInFlight = null;
      });
    }
  })();

  return refreshInFlight;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  query?: Record<string, unknown>;
  signal?: AbortSignal;
  /** Send an Idempotency-Key. Defaults to true for POST. */
  idempotent?: boolean;
  /** Skip the automatic refresh-and-retry (used by auth calls themselves). */
  skipRefresh?: boolean;
  headers?: Record<string, string>;
}

function buildUrl(path: string, query?: Record<string, unknown>): string {
  const url = new URL(`${API_BASE}${path}`, window.location.origin);

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value === undefined || value === null || value === '') continue;

    if (Array.isArray(value)) {
      // Laravel expects repeated `key[]=` for array query parameters.
      value.forEach((entry) => url.searchParams.append(`${key}[]`, String(entry)));
    } else if (typeof value === 'boolean') {
      // `String(true)` is "true", which Laravel's `boolean` validation rule
      // rejects — it accepts 1/0/"1"/"0" and nothing else. Sending the digit
      // avoids a 422 that would otherwise depend on which flag was set.
      url.searchParams.set(key, value ? '1' : '0');
    } else {
      url.searchParams.set(key, String(value));
    }
  }

  return url.toString();
}

function newRequestId(): string {
  return crypto.randomUUID();
}

async function toApiError(response: Response, requestId: string): Promise<ApiError> {
  let payload: unknown;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  const parsed = ErrorEnvelopeSchema.safeParse(payload);
  if (parsed.success) {
    const { code, message, details, request_id } = parsed.data.error;
    return new ApiError(response.status, code, message, details ?? null, request_id ?? requestId);
  }

  return new ApiError(
    response.status,
    'unexpected_response',
    response.statusText || 'The server returned an unexpected response.',
    null,
    requestId,
  );
}

export async function request<T>(
  path: string,
  schema: z.ZodType<T>,
  options: RequestOptions = {},
): Promise<T> {
  const method = options.method ?? 'GET';
  const requestId = newRequestId();

  const send = async (token: string | null): Promise<Response> => {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Request-Id': requestId,
      ...options.headers,
    };

    if (token) headers.Authorization = `Bearer ${token}`;
    if (currentOrganization) headers['X-Organization'] = currentOrganization;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';

    /**
     * A fresh key per *attempt* would defeat the purpose — the point is that
     * a retry of the same logical operation carries the same key. The key is
     * derived from the request id, which is stable across the refresh-retry
     * below, so an expired token mid-create cannot produce two incidents.
     */
    const wantsIdempotency = options.idempotent ?? method === 'POST';
    if (wantsIdempotency) headers['Idempotency-Key'] = requestId;

    return fetch(buildUrl(path, options.query), {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  };

  let response = await send(accessToken);

  // One transparent retry after refreshing. Only for an expired token: a
  // revoked or malformed one will not become valid by asking again.
  if (response.status === 401 && !options.skipRefresh) {
    const error = await toApiError(response.clone(), requestId);

    if (error.code === 'token_expired' || error.code === 'unauthenticated') {
      const token = await refreshAccessToken();
      if (token) {
        response = await send(token);
      }
    }
  }

  if (!response.ok) {
    throw await toApiError(response, requestId);
  }

  if (response.status === 204) {
    return schema.parse(undefined);
  }

  const payload = await response.json();
  const parsed = schema.safeParse(payload);

  if (!parsed.success) {
    // A contract mismatch is a bug, not a user error. Fail loudly and name the
    // offending path rather than letting an undefined propagate into the tree.
    throw new ApiError(
      response.status,
      'schema_mismatch',
      `The API returned an unexpected shape for ${method} ${path}.`,
      parsed.error.issues.slice(0, 5),
      requestId,
    );
  }

  return parsed.data;
}

/** DELETE and other 204 endpoints, where there is no body to validate. */
export async function requestNoContent(path: string, options: RequestOptions = {}): Promise<void> {
  const method = options.method ?? 'DELETE';
  const requestId = newRequestId();

  const send = async (token: string | null): Promise<Response> => {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Request-Id': requestId,
      ...options.headers,
    };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (currentOrganization) headers['X-Organization'] = currentOrganization;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';

    return fetch(buildUrl(path, options.query), {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  };

  let response = await send(accessToken);

  if (response.status === 401 && !options.skipRefresh) {
    const token = await refreshAccessToken();
    if (token) response = await send(token);
  }

  if (!response.ok) {
    throw await toApiError(response, requestId);
  }
}

/**
 * Downloads a file from an authenticated endpoint.
 *
 * A plain `<a href>` cannot carry the bearer token, and moving the credential
 * into the query string to work around that would put it in the browser's
 * history and every access log between here and the server. Fetching to a blob
 * keeps the token in a header where it belongs; the object URL is revoked
 * immediately so the blob does not sit in memory for the life of the tab.
 */
export async function download(path: string, options: RequestOptions = {}): Promise<string> {
  const requestId = newRequestId();

  const send = async (token: string | null): Promise<Response> => {
    const headers: Record<string, string> = { 'X-Request-Id': requestId, ...options.headers };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (currentOrganization) headers['X-Organization'] = currentOrganization;

    return fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      credentials: 'same-origin',
      signal: options.signal,
    });
  };

  let response = await send(accessToken);

  if (response.status === 401 && !options.skipRefresh) {
    const token = await refreshAccessToken();
    if (token) response = await send(token);
  }

  if (!response.ok) {
    throw await toApiError(response, requestId);
  }

  // Prefer the server's filename; it carries the tenant slug and a timestamp.
  const disposition = response.headers.get('content-disposition') ?? '';
  const match = /filename="?([^";]+)"?/i.exec(disposition);
  const filename = match?.[1] ?? path.split('/').pop() ?? 'download';

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);

  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);

  return filename;
}

export { refreshAccessToken };
