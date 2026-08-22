import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { request, requestNoContent } from '@/lib/api-client';
import {
  IncidentCommentSchema,
  IncidentEventSchema,
  IncidentSchema,
  IncidentUpdateSchema,
  paginated,
  wrapped,
  type Incident,
  type Status,
} from '@/lib/schemas';
import { incidentKeys } from './queryKeys';

const IncidentListSchema = paginated(IncidentSchema);
const IncidentDetailSchema = wrapped(IncidentSchema);
const TimelineSchema = paginated(IncidentEventSchema);
const CommentListSchema = paginated(IncidentCommentSchema);
const UpdateListSchema = paginated(IncidentUpdateSchema);

export interface IncidentFilters {
  status?: Status[];
  severity?: string[];
  service_id?: number | null;
  assignee_id?: number | null;
  q?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
  active_only?: boolean;
}

export function useIncidents(filters: IncidentFilters) {
  return useQuery({
    queryKey: incidentKeys.list(filters as Record<string, unknown>),
    queryFn: ({ signal }) =>
      request('/incidents', IncidentListSchema, { query: filters as Record<string, unknown>, signal }),
    // Keeps the previous page on screen while the next one loads, so paging
    // and filtering do not blank the table on every keystroke.
    placeholderData: (previous) => previous,
  });
}

export function useIncident(id: number | undefined) {
  return useQuery({
    queryKey: incidentKeys.detail(id ?? 0),
    queryFn: ({ signal }) => request(`/incidents/${id}`, IncidentDetailSchema, { signal }),
    enabled: id !== undefined,
  });
}

export function useIncidentTimeline(id: number | undefined) {
  return useQuery({
    queryKey: incidentKeys.timeline(id ?? 0),
    queryFn: ({ signal }) =>
      request(`/incidents/${id}/events`, TimelineSchema, { query: { per_page: 100 }, signal }),
    enabled: id !== undefined,
  });
}

export function useIncidentComments(id: number | undefined) {
  return useQuery({
    queryKey: incidentKeys.comments(id ?? 0),
    queryFn: ({ signal }) => request(`/incidents/${id}/comments`, CommentListSchema, { signal }),
    enabled: id !== undefined,
  });
}

export function useIncidentUpdates(id: number | undefined) {
  return useQuery({
    queryKey: incidentKeys.updates(id ?? 0),
    queryFn: ({ signal }) => request(`/incidents/${id}/updates`, UpdateListSchema, { signal }),
    enabled: id !== undefined,
  });
}

export interface CreateIncidentInput {
  title: string;
  description?: string;
  impact?: string;
  severity: string;
  service_id?: number | null;
  commander_id?: number | null;
}

export function useCreateIncident() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: CreateIncidentInput) =>
      request('/incidents', IncidentDetailSchema, { method: 'POST', body: input }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: incidentKeys.lists() });
      void queryClient.invalidateQueries({ queryKey: ['metrics'] });
    },
  });
}

/**
 * Status transition with an optimistic update.
 *
 * Acknowledging a page is the single most time-critical action in the product,
 * and it happens on a phone, on hotel wifi, at 3am. Waiting for a round-trip
 * before the button visibly responds is what makes people tap it three times.
 *
 * The rollback is not optional decoration: the server enforces a state machine,
 * so a transition genuinely can be refused (someone else resolved it first).
 * `onError` restores the exact snapshot rather than refetching, so the UI never
 * shows a state that was never true.
 */
export function useTransitionIncident(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { status: Status; note?: string; public?: boolean }) =>
      request(`/incidents/${incidentId}/status`, IncidentDetailSchema, {
        method: 'POST',
        body: input,
      }),

    onMutate: async (input) => {
      // Stop any in-flight refetch from landing on top of the optimistic value.
      await queryClient.cancelQueries({ queryKey: incidentKeys.detail(incidentId) });

      const previous = queryClient.getQueryData(incidentKeys.detail(incidentId));

      queryClient.setQueryData(
        incidentKeys.detail(incidentId),
        (current: { data: Incident } | undefined) => {
          if (!current) return current;

          return {
            ...current,
            data: {
              ...current.data,
              status: {
                ...current.data.status,
                value: input.status,
                label: titleFor(input.status),
                is_active: ACTIVE_STATUSES.includes(input.status),
                // Emptied deliberately: the client does not own the state
                // machine, so it must not guess which buttons come next. The
                // server's response repopulates them a moment later.
                allowed_transitions: [],
              },
            },
          };
        },
      );

      return { previous };
    },

    onError: (_error, _input, context) => {
      if (context?.previous !== undefined) {
        queryClient.setQueryData(incidentKeys.detail(incidentId), context.previous);
      }
    },

    onSettled: () => {
      void invalidateIncident(queryClient, incidentId);
    },
  });
}

export function useAddComment(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: string) =>
      request(`/incidents/${incidentId}/comments`, wrapped(IncidentCommentSchema), {
        method: 'POST',
        body: { body },
      }),

    /**
     * Optimistic comment.
     *
     * During an incident, the comment thread is a conversation — a visible lag
     * between typing and seeing your own message makes people repeat
     * themselves. The placeholder carries a negative id so the reconciliation
     * after the real response can never collide with a server-assigned one.
     */
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: incidentKeys.comments(incidentId) });
      const previous = queryClient.getQueryData(incidentKeys.comments(incidentId));

      queryClient.setQueryData(
        incidentKeys.comments(incidentId),
        (current: z.infer<typeof CommentListSchema> | undefined) => {
          if (!current) return current;

          return {
            ...current,
            data: [
              {
                id: -Date.now(),
                body,
                edited: false,
                author: null,
                created_at: new Date().toISOString(),
                updated_at: null,
                can_delete: false,
              },
              ...current.data,
            ],
          };
        },
      );

      return { previous };
    },

    onError: (_error, _body, context) => {
      if (context?.previous !== undefined) {
        queryClient.setQueryData(incidentKeys.comments(incidentId), context.previous);
      }
    },

    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: incidentKeys.comments(incidentId) });
      void queryClient.invalidateQueries({ queryKey: incidentKeys.timeline(incidentId) });
    },
  });
}

export function usePostUpdate(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { message: string; public?: boolean }) =>
      request(`/incidents/${incidentId}/updates`, wrapped(IncidentUpdateSchema), {
        method: 'POST',
        body: input,
      }),
    onSuccess: () => void invalidateIncident(queryClient, incidentId),
  });
}

export function useAssignResponder(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) =>
      request(`/incidents/${incidentId}/assignees`, z.object({ data: z.unknown() }), {
        method: 'POST',
        body: { user_id: userId },
      }),
    onSuccess: () => void invalidateIncident(queryClient, incidentId),
  });
}

export function useUnassignResponder(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) =>
      requestNoContent(`/incidents/${incidentId}/assignees/${userId}`, { method: 'DELETE' }),
    onSuccess: () => void invalidateIncident(queryClient, incidentId),
  });
}

export function useChangeSeverity(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { severity: string; reason?: string }) =>
      request(`/incidents/${incidentId}/severity`, IncidentDetailSchema, {
        method: 'POST',
        body: input,
        idempotent: false,
      }),
    onSuccess: () => void invalidateIncident(queryClient, incidentId),
  });
}

function invalidateIncident(queryClient: QueryClient, incidentId: number): Promise<unknown> {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: incidentKeys.detail(incidentId) }),
    queryClient.invalidateQueries({ queryKey: incidentKeys.timeline(incidentId) }),
    queryClient.invalidateQueries({ queryKey: incidentKeys.lists() }),
    queryClient.invalidateQueries({ queryKey: ['metrics'] }),
  ]);
}

const ACTIVE_STATUSES: Status[] = ['open', 'acknowledged', 'mitigated'];

function titleFor(status: Status): string {
  return status.charAt(0).toUpperCase() + status.slice(1);
}
