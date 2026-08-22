import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './api-client';

/**
 * Shared query client.
 *
 * The retry policy is the part worth reading. Retrying a 403 or a 422 is pure
 * noise — the answer will not change — and retrying a 401 fights with the
 * refresh-and-retry already built into the API client. So only genuinely
 * transient failures are retried, and only a couple of times: during an
 * incident, a dashboard that hammers a struggling API is part of the problem.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Live updates arrive over SSE, so aggressive polling is unnecessary.
        // A short stale time still covers the window before the stream connects
        // and the case where it is unavailable entirely.
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: true,
        refetchOnReconnect: true,

        retry: (failureCount, error) => {
          if (error instanceof ApiError) {
            if (!error.isTransient) return false;
            // Respect a 429 rather than compounding it.
            if (error.status === 429) return failureCount < 1;
          }
          return failureCount < 2;
        },

        retryDelay: (attempt) => Math.min(1_000 * 2 ** attempt, 8_000),
      },

      mutations: {
        // A write is never retried automatically. The user pressed a button
        // once; silently pressing it again on their behalf is how a single
        // "Resolve" becomes two timeline events.
        retry: false,
      },
    },
  });
}
