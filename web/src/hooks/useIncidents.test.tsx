import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { useAddComment, useTransitionIncident } from './useIncidents';
import { incidentKeys } from './queryKeys';
import { setAccessToken, setOrganization } from '@/lib/api-client';
import type { Incident } from '@/lib/schemas';

/**
 * Optimistic updates, and — the part that actually matters — their rollback.
 *
 * The server enforces a state machine, so a transition genuinely can be
 * refused: someone else resolved the incident a second earlier. Applying the
 * change locally and *not* restoring it on rejection leaves the responder
 * looking at a status that was never true, on the one screen where that is
 * least acceptable.
 */

const INCIDENT_ID = 42;

function incident(overrides: Partial<Incident['status']> = {}): { data: Incident } {
  return {
    data: {
      id: INCIDENT_ID,
      reference: 'INC-0042',
      title: 'Checkout is down',
      description: null,
      impact: null,
      severity: {
        value: 'sev1',
        label: 'SEV-1 — Critical',
        weight: 1,
        requires_postmortem: true,
        acknowledgement_target_minutes: 5,
      },
      status: {
        value: 'acknowledged',
        label: 'Acknowledged',
        is_active: true,
        allowed_transitions: ['mitigated', 'resolved'],
        ...overrides,
      },
      source: 'web',
      external_reference: null,
      timestamps: {
        detected_at: null, created_at: null, acknowledged_at: null,
        mitigated_at: null, resolved_at: null, closed_at: null, updated_at: null,
      },
      durations: {
        time_to_acknowledge_seconds: 60,
        time_to_resolve_seconds: null,
        open_for_seconds: 300,
      },
    } as Incident,
  };
}

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

function freshClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
}

beforeEach(() => {
  setAccessToken('token');
  setOrganization('northwind');
});

afterEach(() => {
  setAccessToken(null);
  setOrganization(null);
  vi.unstubAllGlobals();
});

describe('useTransitionIncident', () => {
  it('applies the new status before the server answers', async () => {
    const client = freshClient();
    client.setQueryData(incidentKeys.detail(INCIDENT_ID), incident());

    let release!: () => void;
    const pending = new Promise<void>((resolve) => { release = resolve; });

    vi.stubGlobal('fetch', vi.fn(async () => {
      await pending;
      return new Response(JSON.stringify(incident({ value: 'resolved' })), {
        status: 200, headers: { 'Content-Type': 'application/json' },
      });
    }));

    const { result } = renderHook(() => useTransitionIncident(INCIDENT_ID), {
      wrapper: wrapper(client),
    });

    result.current.mutate({ status: 'resolved' });

    // Acknowledging a page happens on a phone at 3am; waiting a round-trip
    // before the button responds is what makes people tap it three times.
    await waitFor(() => {
      const cached = client.getQueryData<{ data: Incident }>(incidentKeys.detail(INCIDENT_ID));
      expect(cached?.data.status.value).toBe('resolved');
    });

    // The client does not own the state machine, so it must not guess what
    // comes next — the server repopulates this a moment later.
    const cached = client.getQueryData<{ data: Incident }>(incidentKeys.detail(INCIDENT_ID));
    expect(cached?.data.status.allowed_transitions).toEqual([]);

    release();
  });

  it('restores the exact previous state when the server refuses', async () => {
    const client = freshClient();
    const original = incident();
    client.setQueryData(incidentKeys.detail(INCIDENT_ID), original);

    vi.stubGlobal('fetch', vi.fn(async () =>
      new Response(
        JSON.stringify({
          error: { code: 'incident.illegal_transition', message: 'Cannot move from Acknowledged to Closed.' },
        }),
        { status: 422, headers: { 'Content-Type': 'application/json' } },
      ),
    ));

    const { result } = renderHook(() => useTransitionIncident(INCIDENT_ID), {
      wrapper: wrapper(client),
    });

    result.current.mutate({ status: 'closed' });

    await waitFor(() => expect(result.current.isError).toBe(true));

    // Restored from the snapshot, not refetched — the UI must never show a
    // state that was never true, not even briefly.
    const cached = client.getQueryData<{ data: Incident }>(incidentKeys.detail(INCIDENT_ID));
    expect(cached?.data.status.value).toBe('acknowledged');
    expect(cached?.data.status.allowed_transitions).toEqual(['mitigated', 'resolved']);
  });
});

describe('useAddComment', () => {
  it('shows the comment immediately and marks it unconfirmed', async () => {
    const client = freshClient();
    client.setQueryData(incidentKeys.comments(INCIDENT_ID), { data: [] });

    let release!: () => void;
    const pending = new Promise<void>((resolve) => { release = resolve; });

    vi.stubGlobal('fetch', vi.fn(async () => {
      await pending;
      return new Response(JSON.stringify({ data: {} }), {
        status: 201, headers: { 'Content-Type': 'application/json' },
      });
    }));

    const { result } = renderHook(() => useAddComment(INCIDENT_ID), { wrapper: wrapper(client) });

    result.current.mutate('Rotterdam is counting stock by hand.');

    await waitFor(() => {
      const cached = client.getQueryData<{ data: Array<{ id: number; body: string }> }>(
        incidentKeys.comments(INCIDENT_ID),
      );
      expect(cached?.data[0]?.body).toBe('Rotterdam is counting stock by hand.');
      // A negative id marks the placeholder, so it can never collide with a
      // server-assigned one during reconciliation.
      expect(cached!.data[0]!.id).toBeLessThan(0);
    });

    release();
  });

  it('removes the placeholder if the comment fails to send', async () => {
    const client = freshClient();
    client.setQueryData(incidentKeys.comments(INCIDENT_ID), { data: [] });

    vi.stubGlobal('fetch', vi.fn(async () =>
      new Response(JSON.stringify({ error: { code: 'forbidden', message: 'no' } }), {
        status: 403, headers: { 'Content-Type': 'application/json' },
      }),
    ));

    const { result } = renderHook(() => useAddComment(INCIDENT_ID), { wrapper: wrapper(client) });

    result.current.mutate('This will not send.');

    await waitFor(() => expect(result.current.isError).toBe(true));

    // Leaving it on screen would tell the responder their message reached the
    // room when it did not.
    const cached = client.getQueryData<{ data: unknown[] }>(incidentKeys.comments(INCIDENT_ID));
    expect(cached?.data).toEqual([]);
  });
});
