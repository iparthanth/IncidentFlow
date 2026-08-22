import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { request } from '@/lib/api-client';
import { RealtimeEnvelopeSchema, RealtimeTicketSchema, wrapped } from '@/lib/schemas';
import type { RealtimeEnvelope } from '@/lib/schemas';
import { incidentKeys } from '@/hooks/queryKeys';

const TicketResponseSchema = wrapped(RealtimeTicketSchema);

export type StreamStatus = 'connecting' | 'live' | 'reconnecting' | 'offline';

interface Options {
  /** Narrow the stream to one incident's events in addition to the org feed. */
  incidentId?: number;
  enabled?: boolean;
  onEvent?: (event: RealtimeEnvelope) => void;
}

/**
 * Live incident updates over Server-Sent Events.
 *
 * Three things here are not obvious and are the whole reason this is a hook
 * rather than three lines of `new EventSource(...)`:
 *
 * 1. **The ticket outlives nothing.** Stream credentials are valid for 60
 *    seconds. `EventSource` reconnects on its own — but it reconnects to the
 *    *same URL*, with the same now-expired ticket, forever. So the `error`
 *    handler closes the stream and re-opens it with a freshly minted ticket;
 *    the browser's built-in retry is deliberately not relied upon.
 *
 * 2. **A gap is reported, not hidden.** Redis pub/sub is fire-and-forget, so a
 *    reconnect can miss events. The server answers `Last-Event-ID` with either
 *    a replay or an explicit `stream.gap`. On a gap we refetch from PostgreSQL
 *    — the source of truth — because a timeline with a silent hole in it is
 *    worse than a brief loading state.
 *
 * 3. **Events invalidate, they do not patch.** It is tempting to splice the
 *    payload straight into the cache. But the envelope carries what changed,
 *    not the full resource, and an incident row assembled from partial events
 *    drifts from the server's version in ways nobody can debug later. Letting
 *    React Query refetch keeps one source of truth.
 */
export function useIncidentStream({ incidentId, enabled = true, onEvent }: Options = {}) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<StreamStatus>('connecting');
  const [lastEventAt, setLastEventAt] = useState<Date | null>(null);

  const sourceRef = useRef<EventSource | null>(null);
  const lastEventIdRef = useRef<string | null>(null);
  const attemptRef = useRef(0);
  const retryTimerRef = useRef<number | null>(null);
  const cancelledRef = useRef(false);
  const onEventRef = useRef(onEvent);

  // Kept in a ref so a caller passing an inline callback does not tear the
  // stream down and rebuild it on every render.
  useEffect(() => {
    onEventRef.current = onEvent;
  }, [onEvent]);

  useEffect(() => {
    // Nothing to set here: "offline" is derived from `enabled` on the way out,
    // so the effect never has to synchronise state React already knows.
    if (!enabled) return;

    cancelledRef.current = false;

    const cleanup = () => {
      sourceRef.current?.close();
      sourceRef.current = null;
      if (retryTimerRef.current !== null) {
        window.clearTimeout(retryTimerRef.current);
        retryTimerRef.current = null;
      }
    };

    const scheduleReconnect = () => {
      if (cancelledRef.current) return;

      attemptRef.current += 1;
      setStatus('reconnecting');

      // Exponential backoff with jitter. Without jitter, every browser that
      // was connected to a node that just restarted comes back in the same
      // millisecond — a thundering herd precisely when the tier is weakest.
      const base = Math.min(30_000, 1_000 * 2 ** Math.min(attemptRef.current, 5));
      const delay = base / 2 + Math.random() * (base / 2);

      retryTimerRef.current = window.setTimeout(() => void connect(), delay);
    };

    const handleEnvelope = (raw: string, eventId: string | null) => {
      let decoded: unknown;
      try {
        decoded = JSON.parse(raw);
      } catch {
        return;
      }

      const parsed = RealtimeEnvelopeSchema.safeParse(decoded);
      if (!parsed.success) return;

      const envelope = parsed.data;
      if (eventId) lastEventIdRef.current = eventId;
      setLastEventAt(new Date());

      onEventRef.current?.(envelope);

      // Invalidate rather than patch — see the note above.
      void queryClient.invalidateQueries({ queryKey: incidentKeys.lists() });
      void queryClient.invalidateQueries({ queryKey: ['metrics'] });
      void queryClient.invalidateQueries({ queryKey: ['notifications'] });

      if (envelope.incident_id != null) {
        void queryClient.invalidateQueries({ queryKey: incidentKeys.detail(envelope.incident_id) });
        void queryClient.invalidateQueries({ queryKey: incidentKeys.timeline(envelope.incident_id) });
        void queryClient.invalidateQueries({ queryKey: incidentKeys.comments(envelope.incident_id) });
      }
    };

    const connect = async () => {
      if (cancelledRef.current) return;

      try {
        const { data: ticket } = await request('/realtime/ticket', TicketResponseSchema, {
          method: 'POST',
          idempotent: false,
        });

        if (cancelledRef.current) return;

        const topics = [...ticket.topics];
        if (incidentId != null) topics.push(`incident:${incidentId}`);

        const url = new URL(ticket.stream_url, window.location.origin);
        url.pathname = `${url.pathname.replace(/\/$/, '')}`;
        url.searchParams.set('ticket', ticket.ticket);
        url.searchParams.set('topics', topics.join(','));

        // EventSource cannot set headers, so the resume cursor goes in the
        // query string on a manual reconnect. (The browser's own retry uses
        // the Last-Event-ID header, which we never get to reach here.)
        if (lastEventIdRef.current) {
          url.searchParams.set('last_event_id', lastEventIdRef.current);
        }

        const source = new EventSource(url.toString());
        sourceRef.current = source;

        source.addEventListener('stream.open', () => {
          attemptRef.current = 0;
          setStatus('live');
        });

        source.addEventListener('stream.gap', () => {
          // The cursor aged out of the server's replay buffer. Refetch
          // everything rather than pretend the stream was continuous.
          void queryClient.invalidateQueries();
        });

        source.addEventListener('stream.reconnect', () => {
          // The node is draining for a deploy. Move to another one promptly
          // instead of waiting for the socket to die.
          source.close();
          scheduleReconnect();
        });

        source.onmessage = (event: MessageEvent<string>) => {
          handleEnvelope(event.data, event.lastEventId || null);
        };

        // Named events (incident.created, incident.status_changed, …) do not
        // fire onmessage, so every known type is wired to the same handler.
        for (const type of KNOWN_EVENT_TYPES) {
          source.addEventListener(type, (event) => {
            const message = event as MessageEvent<string>;
            handleEnvelope(message.data, message.lastEventId || null);
          });
        }

        source.onerror = () => {
          source.close();
          sourceRef.current = null;
          scheduleReconnect();
        };
      } catch {
        scheduleReconnect();
      }
    };

    void connect();

    return () => {
      cancelledRef.current = true;
      cleanup();
    };
  }, [enabled, incidentId, queryClient]);

  return { status: enabled ? status : ('offline' as StreamStatus), lastEventAt };
}

/** Mirrors App\Enums\IncidentEventType on the API side. */
const KNOWN_EVENT_TYPES = [
  'incident.created',
  'incident.status_changed',
  'incident.severity_changed',
  'incident.updated',
  'incident.assigned',
  'incident.unassigned',
  'incident.commander_changed',
  'incident.commented',
  'incident.update_posted',
  'incident.acknowledged',
  'incident.mitigated',
  'incident.resolved',
  'incident.closed',
  'incident.reopened',
  'incident.deleted',
  'postmortem.drafted',
  'postmortem.published',
] as const;
