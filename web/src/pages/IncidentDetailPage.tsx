import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Loader2, Send } from 'lucide-react';
import { useAuth } from '@/auth/useAuth';
import {
  useAddComment,
  useIncident,
  useIncidentComments,
  useIncidentTimeline,
  useTransitionIncident,
} from '@/hooks/useIncidents';
import { SeverityBadge, StatusBadge } from '@/components/Badges';
import { Timeline } from '@/components/Timeline';
import { ResponderControl, SeverityControl, UpdatesPanel } from '@/components/IncidentControls';
import { PostmortemEditor } from '@/components/PostmortemEditor';
import { FullPageSpinner } from '@/components/FullPageSpinner';
import { formatAbsolute, formatDuration, formatRelative, initials } from '@/lib/format';
import { ApiError } from '@/lib/api-client';
import type { Status } from '@/lib/schemas';

export function IncidentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const incidentId = id ? Number(id) : undefined;
  const { can } = useAuth();

  const { data, isLoading, isError, error } = useIncident(incidentId);
  const { data: timeline } = useIncidentTimeline(incidentId);
  const { data: comments } = useIncidentComments(incidentId);

  /**
   * No stream is opened here, deliberately.
   *
   * `AppLayout` already holds one for the whole session, and every client is
   * implicitly subscribed to its organization topic — which is a topic every
   * incident event carries. So the layout's connection already receives this
   * incident's events, and the stream hook already invalidates the detail,
   * timeline and comment queries for whichever incident an event names.
   *
   * Opening a second connection here bought nothing and cost double: two SSE
   * connections and two short-lived ticket requests per user, plus a second
   * reconnect loop competing with the first.
   */

  if (isLoading) return <FullPageSpinner label="Loading incident…" />;

  if (isError || !data) {
    return (
      <div role="alert" className="rounded-md bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
        {error instanceof ApiError ? error.message : 'This incident could not be loaded.'}
      </div>
    );
  }

  const incident = data.data;

  return (
    <div className="space-y-6">
      <Link
        to="/incidents"
        className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        All incidents
      </Link>

      <header className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-mono text-sm text-slate-500 dark:text-slate-400">{incident.reference}</span>
          <SeverityBadge severity={incident.severity.value} label={incident.severity.label} />
          <StatusBadge status={incident.status.value} label={incident.status.label} />
          {incident.severity.requires_postmortem && (
            <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              Postmortem required
            </span>
          )}
        </div>

        <h1 className="page-title">{incident.title}</h1>

        {incident.description && (
          <p className="max-w-3xl whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">
            {incident.description}
          </p>
        )}
      </header>

      {can('incident.transition') && incidentId !== undefined && (
        <TransitionBar incidentId={incidentId} allowed={incident.status.allowed_transitions} />
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <section aria-labelledby="timeline-heading" className="space-y-3 lg:col-span-2">
          <h2 id="timeline-heading" className="text-sm font-semibold">
            Timeline
          </h2>
          <div className="card p-4">
            <Timeline events={timeline?.data ?? []} />
          </div>

          <h2 className="pt-2 text-sm font-semibold">Updates</h2>
          <div className="card p-4">
            {/* Distinct from the comment thread below: an update is the record
                of communication, and can be marked safe for a status page. */}
            {incidentId !== undefined && (
              <UpdatesPanel incidentId={incidentId} canPost={can('update.create')} />
            )}
          </div>

          {/* Shown once the incident is settled, or whenever severity policy
              demands one — a SEV-1 that never gets a postmortem is the failure
              this section exists to make visible. */}
          {incidentId !== undefined &&
            (!incident.status.is_active || incident.severity.requires_postmortem) && (
              <>
                <h2 className="pt-2 text-sm font-semibold">
                  Postmortem
                  {incident.severity.requires_postmortem && (
                    <span className="ml-2 text-xs font-normal text-slate-500 dark:text-slate-400">
                      required for {incident.severity.value.toUpperCase().replace('SEV', 'SEV-')}
                    </span>
                  )}
                </h2>
                <div className="card p-4">
                  <PostmortemEditor
                    incidentId={incidentId}
                    canManage={can('postmortem.manage')}
                    canPublish={can('postmortem.publish')}
                  />
                </div>
              </>
            )}

          <h2 className="pt-2 text-sm font-semibold">Discussion</h2>
          <div className="space-y-3 card p-4">
            {can('comment.create') && incidentId !== undefined && <CommentForm incidentId={incidentId} />}

            <ul className="space-y-3">
              {(comments?.data ?? []).map((comment) => (
                <li key={comment.id} className="flex gap-3">
                  <span
                    aria-hidden="true"
                    className="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-200 text-[10px] font-medium dark:bg-slate-700"
                  >
                    {initials(comment.author?.name)}
                  </span>
                  <div className="min-w-0">
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      <span className="font-medium text-slate-700 dark:text-slate-200">
                        {comment.author?.name ?? 'You'}
                      </span>{' '}
                      · {formatRelative(comment.created_at)}
                      {/* A negative id marks the optimistic placeholder that has
                          not yet been confirmed by the server. */}
                      {comment.id < 0 && <span className="ml-1 italic">sending…</span>}
                    </p>
                    <p className="whitespace-pre-line text-sm text-slate-800 dark:text-slate-200">
                      {comment.body}
                    </p>
                  </div>
                </li>
              ))}
            </ul>

            {(comments?.data ?? []).length === 0 && (
              <p className="text-sm text-slate-500 dark:text-slate-400">No comments yet.</p>
            )}
          </div>
        </section>

        <aside className="space-y-4">
          <dl className="space-y-3 card p-4 text-sm">
            <Detail label="Service" value={incident.service?.name ?? 'Unassigned'} />
            <Detail label="Reported by" value={incident.reporter?.name ?? 'Unknown'} />
            <Detail label="Commander" value={incident.commander?.name ?? 'Not appointed'} />
            <Detail
              label="Opened"
              value={formatAbsolute(incident.timestamps.created_at)}
              hint={formatRelative(incident.timestamps.created_at)}
            />
            <Detail
              label="Time to acknowledge"
              value={formatDuration(incident.durations.time_to_acknowledge_seconds)}
              hint={`Target ${incident.severity.acknowledgement_target_minutes}m`}
            />
            <Detail
              label={incident.status.is_active ? 'Running for' : 'Time to resolve'}
              value={formatDuration(
                incident.durations.open_for_seconds ?? incident.durations.time_to_resolve_seconds,
              )}
            />
          </dl>

          <div className="card p-4">
            {can('incident.assign') ? (
              <ResponderControl incident={incident} />
            ) : (
              <>
                <h2 className="text-sm font-semibold">Responders</h2>
                {(incident.assignees ?? []).length === 0 ? (
                  <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Nobody assigned yet.</p>
                ) : (
                  <ul className="mt-2 space-y-1.5">
                    {(incident.assignees ?? []).map((assignee) => (
                      <li key={assignee.id} className="flex items-center gap-2 text-sm">
                        <span
                          aria-hidden="true"
                          className="grid h-6 w-6 place-items-center rounded-full bg-slate-200 text-[10px] font-medium dark:bg-slate-700"
                        >
                          {initials(assignee.name)}
                        </span>
                        {assignee.name}
                      </li>
                    ))}
                  </ul>
                )}
              </>
            )}
          </div>

          {/* Commander-only: escalating pages the on-call population, and
              de-escalating changes what the postmortem policy requires. */}
          {can('incident.command') && incident.status.is_active && (
            <div className="card p-4">
              <SeverityControl incident={incident} />
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}

/**
 * Transition controls.
 *
 * The buttons are rendered from `allowed_transitions`, which the API computes
 * from the state machine. The client therefore cannot offer a move the server
 * would reject — no duplicated rules, no drift, and no user discovering the
 * lifecycle through a sequence of 422s.
 */
function TransitionBar({ incidentId, allowed }: { incidentId: number; allowed: Status[] }) {
  const transition = useTransitionIncident(incidentId);
  const [error, setError] = useState<string | null>(null);

  if (allowed.length === 0) {
    return (
      <p className="rounded-md bg-slate-100 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
        This incident is closed. Further findings belong in the postmortem.
      </p>
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-2">
        {allowed.map((status) => (
          <button
            key={status}
            type="button"
            disabled={transition.isPending}
            onClick={() => {
              setError(null);
              transition.mutate(
                { status },
                {
                  onError: (cause) =>
                    setError(cause instanceof ApiError ? cause.message : 'The transition was rejected.'),
                },
              );
            }}
            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium capitalize hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700"
          >
            Mark {status}
          </button>
        ))}
      </div>

      {error && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error}
        </p>
      )}
    </div>
  );
}

function CommentForm({ incidentId }: { incidentId: number }) {
  const addComment = useAddComment(incidentId);
  const [body, setBody] = useState('');

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const trimmed = body.trim();
    if (trimmed === '') return;

    // Cleared immediately rather than in onSuccess: the optimistic update has
    // already put the comment on screen, so leaving the text in the box would
    // make it look like the message had not been sent.
    setBody('');
    addComment.mutate(trimmed);
  }

  return (
    <form onSubmit={handleSubmit} className="flex gap-2">
      <label htmlFor="comment" className="sr-only">
        Add a comment
      </label>
      <input
        id="comment"
        value={body}
        onChange={(event) => setBody(event.target.value)}
        placeholder="Add what you are seeing…"
        className="flex-1 input"
      />
      <button
        type="submit"
        disabled={body.trim() === ''}
        className="btn btn-primary"
      >
        {addComment.isPending ? (
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
        ) : (
          <Send className="h-4 w-4" aria-hidden="true" />
        )}
        Send
      </button>
    </form>
  );
}

function Detail({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div>
      <dt className="text-xs text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="text-slate-800 dark:text-slate-200">
        {value}
        {hint && <span className="ml-1 text-xs text-slate-400">({hint})</span>}
      </dd>
    </div>
  );
}
