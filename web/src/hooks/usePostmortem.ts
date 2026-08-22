import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, request } from '@/lib/api-client';
import { PostmortemSchema, wrapped } from '@/lib/schemas';
import type { Postmortem } from '@/lib/schemas';
import { incidentKeys, postmortemKeys } from './queryKeys';

const PostmortemResponseSchema = wrapped(PostmortemSchema);

export interface PostmortemDraft {
  title?: string;
  summary?: string | null;
  root_cause?: string | null;
  contributing_factors?: string | null;
  impact?: string | null;
  resolution?: string | null;
  detection_notes?: string | null;
  lessons_learned?: string | null;
}

/**
 * A postmortem may legitimately not exist yet, so a 404 is an expected answer
 * rather than an error — returning null lets the UI offer to start one instead
 * of rendering a failure state for a document nobody has written.
 */
export function usePostmortem(incidentId: number | undefined) {
  return useQuery({
    queryKey: incidentKeys.postmortem(incidentId ?? 0),
    enabled: incidentId !== undefined,
    queryFn: async ({ signal }): Promise<Postmortem | null> => {
      try {
        const response = await request(
          `/incidents/${incidentId}/postmortem`,
          PostmortemResponseSchema,
          { signal },
        );
        return response.data;
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null;
        throw error;
      }
    },
  });
}

/**
 * Create-or-replace. The endpoint is a PUT because there is exactly one
 * postmortem per incident and the editor saves the whole document, so saving
 * the same draft twice must be one document rather than two.
 */
export function useSavePostmortem(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (draft: PostmortemDraft) =>
      request(`/incidents/${incidentId}/postmortem`, PostmortemResponseSchema, {
        method: 'PUT',
        body: draft,
        idempotent: false,
      }),
    onSuccess: (response) => {
      // Seed the cache from the response so `missing_sections` updates the
      // publish button immediately, without a second round-trip.
      queryClient.setQueryData(incidentKeys.postmortem(incidentId), response.data);
      void queryClient.invalidateQueries({ queryKey: postmortemKeys.all });
    },
  });
}

export function usePublishPostmortem(incidentId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      request(`/incidents/${incidentId}/postmortem/publish`, PostmortemResponseSchema, {
        method: 'POST',
        idempotent: false,
      }),
    onSuccess: (response) => {
      queryClient.setQueryData(incidentKeys.postmortem(incidentId), response.data);
      void queryClient.invalidateQueries({ queryKey: postmortemKeys.all });
      void queryClient.invalidateQueries({ queryKey: incidentKeys.timeline(incidentId) });
    },
  });
}
