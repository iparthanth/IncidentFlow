import { z } from 'zod';

/**
 * The wire contract between the Laravel API (publisher) and this service
 * (fan-out). Laravel's `App\Realtime\RealtimeEvent` value object produces
 * exactly this shape; the schema below is the runtime guard on the consuming
 * side so a bad publisher can never crash a fan-out node.
 *
 * `version` exists so the two services can be deployed independently: a
 * realtime node that does not understand a future envelope version drops the
 * message and increments a counter instead of throwing.
 */
export const ENVELOPE_VERSION = 1;

export const RealtimeEventSchema = z.object({
  version: z.number().int().positive(),
  /** ULID/UUID; doubles as the SSE event id for Last-Event-ID replay. */
  id: z.string().min(1),
  /** Dotted event name, e.g. "incident.status_changed". */
  type: z.string().min(1),
  organization_id: z.number().int().positive(),
  /** Present for every incident-scoped event; absent for org-level events. */
  incident_id: z.number().int().positive().nullable().optional(),
  occurred_at: z.string().min(1),
  actor: z
    .object({
      id: z.number().int().positive().nullable(),
      name: z.string().nullable(),
    })
    .nullable()
    .optional(),
  /** Correlation id of the HTTP request that produced the event. */
  request_id: z.string().nullable().optional(),
  payload: z.record(z.string(), z.unknown()).default({}),
});

export type RealtimeEvent = z.infer<typeof RealtimeEventSchema>;

/** Claims minted by the API for `aud: incidentflow-realtime` tickets. */
export const TicketClaimsSchema = z.object({
  sub: z.string().min(1),
  org_id: z.number().int().positive(),
  role: z.string().min(1),
  name: z.string().optional(),
  jti: z.string().optional(),
  exp: z.number().int().positive(),
  iat: z.number().int().positive().optional(),
});

export type TicketClaims = z.infer<typeof TicketClaimsSchema>;

export interface AuthenticatedPrincipal {
  userId: string;
  organizationId: number;
  role: string;
  name: string | undefined;
  tokenId: string | undefined;
  expiresAt: number;
}

/**
 * A topic is the unit of subscription. Two forms exist:
 *   org:{organizationId}       every event in the organization
 *   incident:{incidentId}      one incident's events
 *
 * Authorization note: topics are *filters*, not access grants. Every event
 * this service ever sees arrives on a per-organization Redis channel that the
 * hub only subscribes to on behalf of an authenticated member of that
 * organization. Subscribing to `incident:99` therefore cannot leak incident 99
 * from another org — that org's events are never routed to this connection in
 * the first place.
 */
export type Topic = `org:${number}` | `incident:${number}`;

export function orgTopic(organizationId: number): Topic {
  return `org:${organizationId}`;
}

export function incidentTopic(incidentId: number): Topic {
  return `incident:${incidentId}`;
}

/** Every topic an event belongs to, most general first. */
export function topicsForEvent(event: RealtimeEvent): Topic[] {
  const topics: Topic[] = [orgTopic(event.organization_id)];
  if (event.incident_id != null) {
    topics.push(incidentTopic(event.incident_id));
  }
  return topics;
}

export const SubscribeCommandSchema = z.object({
  action: z.literal('subscribe'),
  topics: z.array(z.string().min(1)).min(1).max(50),
});

export const UnsubscribeCommandSchema = z.object({
  action: z.literal('unsubscribe'),
  topics: z.array(z.string().min(1)).min(1).max(50),
});

export const PingCommandSchema = z.object({ action: z.literal('ping') });

export const ClientCommandSchema = z.discriminatedUnion('action', [
  SubscribeCommandSchema,
  UnsubscribeCommandSchema,
  PingCommandSchema,
]);

export type ClientCommand = z.infer<typeof ClientCommandSchema>;
