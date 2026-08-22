/**
 * Query key factory.
 *
 * Centralising the keys is what makes cache invalidation reliable. When a
 * realtime event arrives, `incidentKeys.lists()` must invalidate every incident
 * list regardless of the filters it was fetched with — which only works if
 * every list shares a prefix, and only stays working if nobody hand-writes a
 * key at a call site.
 */

export const incidentKeys = {
  all: ['incidents'] as const,
  lists: () => [...incidentKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...incidentKeys.lists(), filters] as const,
  details: () => [...incidentKeys.all, 'detail'] as const,
  detail: (id: number) => [...incidentKeys.details(), id] as const,
  timeline: (id: number) => [...incidentKeys.detail(id), 'timeline'] as const,
  comments: (id: number) => [...incidentKeys.detail(id), 'comments'] as const,
  updates: (id: number) => [...incidentKeys.detail(id), 'updates'] as const,
  postmortem: (id: number) => [...incidentKeys.detail(id), 'postmortem'] as const,
} as const;

export const serviceKeys = {
  all: ['services'] as const,
  lists: () => [...serviceKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...serviceKeys.lists(), filters] as const,
} as const;

export const metricsKeys = {
  all: ['metrics'] as const,
  summary: (days: number) => [...metricsKeys.all, 'summary', days] as const,
  trends: (days: number) => [...metricsKeys.all, 'trends', days] as const,
} as const;

export const memberKeys = {
  all: ['members'] as const,
  list: (filters: Record<string, unknown>) => [...memberKeys.all, 'list', filters] as const,
} as const;

export const notificationKeys = {
  all: ['notifications'] as const,
  list: (unreadOnly: boolean) => [...notificationKeys.all, 'list', unreadOnly] as const,
} as const;

export const auditKeys = {
  all: ['audit-logs'] as const,
  list: (filters: Record<string, unknown>) => [...auditKeys.all, 'list', filters] as const,
} as const;

export const postmortemKeys = {
  all: ['postmortems'] as const,
  list: (filters: Record<string, unknown>) => [...postmortemKeys.all, 'list', filters] as const,
} as const;
