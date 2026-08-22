import { z } from 'zod';

/**
 * Runtime contracts for everything the API returns.
 *
 * TypeScript types vanish at build time, so `as IncidentResponse` is a promise
 * the compiler cannot keep — the moment the API changes shape, the frontend
 * fails with `Cannot read properties of undefined` somewhere three components
 * deep from the fetch. Parsing with zod turns that into one loud, located
 * error at the boundary, which is where a contract mismatch belongs.
 */

export const ErrorEnvelopeSchema = z.object({
  error: z.object({
    code: z.string(),
    message: z.string(),
    details: z.unknown().nullish(),
    request_id: z.string().nullish(),
  }),
});

export type ErrorEnvelope = z.infer<typeof ErrorEnvelopeSchema>;

export const UserSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  avatar_url: z.string().nullable().optional(),
  timezone: z.string().optional(),
  is_active: z.boolean().optional(),
  role: z.string().nullish(),
});

export type User = z.infer<typeof UserSchema>;

export const OrganizationSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  timezone: z.string(),
  settings: z.record(z.string(), z.unknown()).nullish(),
  role: z.string().nullish(),
  created_at: z.string().nullish(),
});

export type Organization = z.infer<typeof OrganizationSchema>;

export const ServiceSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  description: z.string().nullable(),
  owner_team: z.string().nullable(),
  tier: z.number().int(),
  is_active: z.boolean(),
  open_incident_count: z.number().int().optional(),
  created_at: z.string().nullish(),
  updated_at: z.string().nullish(),
});

export type Service = z.infer<typeof ServiceSchema>;

export const SEVERITIES = ['sev1', 'sev2', 'sev3', 'sev4'] as const;
export const STATUSES = ['open', 'acknowledged', 'mitigated', 'resolved', 'closed'] as const;

export type Severity = (typeof SEVERITIES)[number];
export type Status = (typeof STATUSES)[number];

export const IncidentSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  title: z.string(),
  description: z.string().nullable(),
  impact: z.string().nullable(),
  severity: z.object({
    value: z.enum(SEVERITIES),
    label: z.string(),
    weight: z.number().int(),
    requires_postmortem: z.boolean(),
    acknowledgement_target_minutes: z.number().int(),
  }),
  status: z.object({
    value: z.enum(STATUSES),
    label: z.string(),
    is_active: z.boolean(),
    // The server tells the client which buttons to render, so the UI can
    // never offer a transition the state machine will reject.
    allowed_transitions: z.array(z.enum(STATUSES)),
  }),
  source: z.string(),
  external_reference: z.string().nullable(),
  timestamps: z.object({
    detected_at: z.string().nullable(),
    created_at: z.string().nullable(),
    acknowledged_at: z.string().nullable(),
    mitigated_at: z.string().nullable(),
    resolved_at: z.string().nullable(),
    closed_at: z.string().nullable(),
    updated_at: z.string().nullable(),
  }),
  durations: z.object({
    time_to_acknowledge_seconds: z.number().int().nullable(),
    time_to_resolve_seconds: z.number().int().nullable(),
    open_for_seconds: z.number().int().nullable(),
  }),
  service: ServiceSchema.nullish(),
  reporter: UserSchema.nullish(),
  commander: UserSchema.nullish(),
  assignees: z.array(UserSchema).optional(),
  counts: z
    .object({
      comments: z.number().int().optional(),
      updates: z.number().int().optional(),
      events: z.number().int().optional(),
    })
    .optional(),
});

export type Incident = z.infer<typeof IncidentSchema>;

export const IncidentEventSchema = z.object({
  id: z.string(),
  type: z.string(),
  summary: z.string(),
  incident_id: z.number().int(),
  actor: z.object({ id: z.number().int().nullable(), name: z.string() }),
  payload: z.record(z.string(), z.unknown()),
  occurred_at: z.string().nullable(),
  request_id: z.string().nullable(),
});

export type IncidentEvent = z.infer<typeof IncidentEventSchema>;

export const IncidentCommentSchema = z.object({
  id: z.number().int(),
  body: z.string(),
  edited: z.boolean(),
  author: UserSchema.nullish(),
  created_at: z.string().nullish(),
  updated_at: z.string().nullish(),
  can_delete: z.boolean(),
});

export type IncidentComment = z.infer<typeof IncidentCommentSchema>;

export const IncidentUpdateSchema = z.object({
  id: z.number().int(),
  message: z.string(),
  is_public: z.boolean(),
  status: z.string().nullable(),
  previous_status: z.string().nullable(),
  author: UserSchema.nullish(),
  created_at: z.string().nullish(),
});

export type IncidentUpdate = z.infer<typeof IncidentUpdateSchema>;

export const PostmortemSchema = z.object({
  id: z.number().int(),
  incident_id: z.number().int(),
  title: z.string(),
  summary: z.string().nullable(),
  root_cause: z.string().nullable(),
  contributing_factors: z.string().nullable(),
  impact: z.string().nullable(),
  resolution: z.string().nullable(),
  detection_notes: z.string().nullable(),
  lessons_learned: z.string().nullable(),
  action_items: z.array(z.record(z.string(), z.unknown())),
  status: z.enum(['draft', 'in_review', 'published']),
  is_editable: z.boolean(),
  missing_sections: z.array(z.string()),
  published_at: z.string().nullable(),
  author: UserSchema.nullish(),
});

export type Postmortem = z.infer<typeof PostmortemSchema>;

export const NotificationSchema = z.object({
  id: z.string(),
  type: z.string(),
  channel: z.string(),
  status: z.string(),
  subject: z.string().nullable(),
  body: z.string().nullable(),
  payload: z.record(z.string(), z.unknown()),
  incident_id: z.number().int().nullable(),
  read_at: z.string().nullable(),
  created_at: z.string().nullish(),
});

export type AppNotification = z.infer<typeof NotificationSchema>;

export const AuditLogSchema = z.object({
  id: z.string(),
  action: z.string(),
  actor: z.object({
    id: z.number().int().nullable(),
    name: z.string().nullish(),
    email: z.string().nullable(),
  }),
  subject: z.object({ type: z.string().nullable(), id: z.number().int().nullable() }),
  changes: z.unknown().nullable(),
  ip_address: z.string().nullable(),
  request_id: z.string().nullable(),
  created_at: z.string().nullish(),
});

export type AuditLog = z.infer<typeof AuditLogSchema>;

const DurationStatsSchema = z.object({
  count: z.number().int(),
  average: z.number().int().nullable(),
  p50: z.number().int().nullable(),
  p90: z.number().int().nullable(),
  p95: z.number().int().nullable(),
  max: z.number().int().nullable(),
});

export const MetricsSummarySchema = z.object({
  period: z.object({ from: z.string(), to: z.string(), days: z.number().int() }),
  totals: z.object({
    created: z.number().int(),
    resolved: z.number().int(),
    currently_open: z.number().int(),
    truncated: z.boolean(),
  }),
  by_status: z.record(z.string(), z.number().int()),
  by_severity: z.record(z.string(), z.number().int()),
  mtta_seconds: DurationStatsSchema,
  mttr_seconds: DurationStatsSchema,
  acknowledgement_sla: z.object({
    overall_attainment: z.number().nullable(),
    acknowledged_incidents: z.number().int(),
    by_severity: z.record(
      z.string(),
      z.object({
        total: z.number().int(),
        within_target: z.number().int(),
        target_minutes: z.number().int(),
        attainment: z.number().nullable(),
      }),
    ),
  }),
});

export type MetricsSummary = z.infer<typeof MetricsSummarySchema>;

export const TrendPointSchema = z.object({
  date: z.string(),
  created: z.number().int(),
  resolved: z.number().int(),
  mttr_seconds: z.number().int().nullable(),
});

export type TrendPoint = z.infer<typeof TrendPointSchema>;

export const MemberSchema = z.object({
  id: z.number().int(),
  organization_id: z.number().int(),
  role: z.string(),
  role_label: z.string(),
  permissions: z.array(z.string()),
  joined_at: z.string().nullable(),
  user: UserSchema.nullish(),
});

export type Member = z.infer<typeof MemberSchema>;

export const RealtimeTicketSchema = z.object({
  ticket: z.string(),
  expires_in: z.number().int(),
  expires_at: z.string(),
  stream_url: z.string(),
  topics: z.array(z.string()),
});

/** Envelope produced by the Laravel publisher; see api RealtimeEvent::toArray(). */
export const RealtimeEnvelopeSchema = z.object({
  version: z.number().int(),
  id: z.string(),
  type: z.string(),
  organization_id: z.number().int(),
  incident_id: z.number().int().nullable().optional(),
  occurred_at: z.string(),
  actor: z.object({ id: z.number().int().nullable(), name: z.string().nullable() }).nullish(),
  request_id: z.string().nullish(),
  payload: z.record(z.string(), z.unknown()),
});

export type RealtimeEnvelope = z.infer<typeof RealtimeEnvelopeSchema>;

/** Laravel's paginator wrapper, generic over the row type. */
export function paginated<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    data: z.array(item),
    links: z.record(z.string(), z.string().nullable()).optional(),
    meta: z
      .object({
        current_page: z.number().int().optional(),
        from: z.number().int().nullable().optional(),
        last_page: z.number().int().optional(),
        per_page: z.number().int().optional(),
        to: z.number().int().nullable().optional(),
        total: z.number().int().optional(),
        // Cursor paginators expose these instead of page numbers.
        next_cursor: z.string().nullable().optional(),
        prev_cursor: z.string().nullable().optional(),
      })
      .optional(),
  });
}

export function wrapped<T extends z.ZodTypeAny>(item: T) {
  return z.object({ data: item });
}
