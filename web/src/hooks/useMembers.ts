import { useQuery } from '@tanstack/react-query';
import { request } from '@/lib/api-client';
import { MemberSchema, paginated } from '@/lib/schemas';
import { memberKeys } from './queryKeys';

const MemberListSchema = paginated(MemberSchema);

/**
 * Organization members.
 *
 * `assignableOnly` asks the server for the roles that can actually be paged.
 * Filtering client-side would work until someone adds a role, at which point
 * the two definitions of "assignable" drift — and the symptom is a picker that
 * offers people the API then refuses.
 */
export function useMembers({ assignableOnly = false }: { assignableOnly?: boolean } = {}) {
  return useQuery({
    queryKey: memberKeys.list({ assignableOnly }),
    queryFn: ({ signal }) =>
      request('/members', MemberListSchema, {
        query: { assignable_only: assignableOnly || undefined, per_page: 100 },
        signal,
      }),
    // The roster changes far less often than incidents do.
    staleTime: 5 * 60_000,
  });
}
