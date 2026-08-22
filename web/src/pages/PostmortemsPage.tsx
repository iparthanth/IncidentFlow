import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { FileText } from 'lucide-react';
import { request } from '@/lib/api-client';
import { paginated, PostmortemSchema } from '@/lib/schemas';
import { postmortemKeys } from '@/hooks/queryKeys';
import { EmptyState } from '@/components/EmptyState';
import { formatRelative } from '@/lib/format';

const PostmortemListSchema = paginated(PostmortemSchema);

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  in_review: 'In review',
  published: 'Published',
};

export function PostmortemsPage() {
  const { data, isLoading } = useQuery({
    queryKey: postmortemKeys.list({}),
    queryFn: ({ signal }) => request('/postmortems', PostmortemListSchema, { signal }),
  });

  const postmortems = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="page-title">Postmortems</h1>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          SEV-1 and SEV-2 incidents require one before they can be considered finished.
        </p>
      </div>

      {isLoading ? (
        <div aria-hidden="true" className="h-32 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" />
      ) : postmortems.length === 0 ? (
        <EmptyState
          icon={FileText}
          title="No postmortems yet"
          description="Start one from any resolved incident. A report with no root cause cannot be published."
        />
      ) : (
        <ul className="space-y-2">
          {postmortems.map((postmortem) => (
            <li
              key={postmortem.id}
              className="card p-4"
            >
              <div className="flex flex-wrap items-center gap-2">
                <Link
                  to={`/incidents/${postmortem.incident_id}`}
                  className="text-sm font-medium underline-offset-2 hover:underline"
                >
                  {postmortem.title}
                </Link>
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  {STATUS_LABELS[postmortem.status] ?? postmortem.status}
                </span>
              </div>

              {postmortem.summary && (
                <p className="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">
                  {postmortem.summary}
                </p>
              )}

              {/* Naming exactly what is missing is more useful than a generic
                  "incomplete" — it turns a blocked publish into a to-do list. */}
              {postmortem.missing_sections.length > 0 && (
                <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                  Cannot publish until these are filled in:{' '}
                  {postmortem.missing_sections.map((section) => section.replace(/_/g, ' ')).join(', ')}
                </p>
              )}

              <p className="mt-2 text-xs text-slate-400">
                {postmortem.published_at
                  ? `Published ${formatRelative(postmortem.published_at)}`
                  : 'Not yet published'}
                {postmortem.author?.name && ` · ${postmortem.author.name}`}
              </p>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
