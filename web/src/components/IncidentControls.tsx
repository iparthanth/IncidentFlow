import { useState, type FormEvent } from 'react';
import { Loader2, UserMinus, UserPlus } from 'lucide-react';
import {
  useAssignResponder,
  useChangeSeverity,
  useIncidentUpdates,
  usePostUpdate,
  useUnassignResponder,
} from '@/hooks/useIncidents';
import { useMembers } from '@/hooks/useMembers';
import { ApiError } from '@/lib/api-client';
import { SEVERITIES, type Incident, type Severity } from '@/lib/schemas';
import { formatRelative, initials } from '@/lib/format';

/**
 * Severity control.
 *
 * A downgrade requires a reason, and the form asks for it *before* submitting
 * rather than letting the server reject the attempt. The rule itself still
 * lives on the API — this is a courtesy, not the enforcement.
 */
export function SeverityControl({ incident }: { incident: Incident }) {
  const changeSeverity = useChangeSeverity(incident.id);
  const [target, setTarget] = useState<Severity | ''>('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const current = incident.severity.value;
  const weightOf = (severity: Severity): number => SEVERITIES.indexOf(severity) + 1;
  const isDowngrade = target !== '' && weightOf(target) > weightOf(current);

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (target === '') return;

    setError(null);
    changeSeverity.mutate(
      { severity: target, reason: reason.trim() || undefined },
      {
        onSuccess: () => {
          setTarget('');
          setReason('');
        },
        onError: (cause) =>
          setError(cause instanceof ApiError ? cause.message : 'The severity change was rejected.'),
      },
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-2">
      <label htmlFor="severity-target" className="block text-sm font-semibold">
        Change severity
      </label>

      <select
        id="severity-target"
        value={target}
        onChange={(event) => setTarget(event.target.value as Severity | '')}
        className="w-full input px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"
      >
        <option value="">Keep {current.toUpperCase().replace('SEV', 'SEV-')}</option>
        {SEVERITIES.filter((severity) => severity !== current).map((severity) => (
          <option key={severity} value={severity}>
            {severity.toUpperCase().replace('SEV', 'SEV-')}
          </option>
        ))}
      </select>

      {isDowngrade && (
        <div>
          <label htmlFor="severity-reason" className="block text-xs text-slate-500 dark:text-slate-400">
            Lowering severity changes what the postmortem policy requires, so it
            goes on the record.
          </label>
          <textarea
            id="severity-reason"
            rows={2}
            required
            minLength={10}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Confirmed limited to one internal dashboard; no customer impact."
            className="mt-1 w-full input px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"
          />
        </div>
      )}

      {error && (
        <p role="alert" className="text-xs text-red-600 dark:text-red-400">
          {error}
        </p>
      )}

      <button
        type="submit"
        disabled={target === '' || changeSeverity.isPending}
        className="flex w-full items-center justify-center gap-1.5 input px-3 py-1.5 text-sm font-medium disabled:opacity-40 dark:border-slate-700"
      >
        {changeSeverity.isPending && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />}
        Apply
      </button>
    </form>
  );
}

/**
 * Responder roster.
 *
 * The picker asks the API for `assignable_only`, so a viewer never appears as
 * an option — an assignment that looks like coverage and provides none is worse
 * than no assignment at all.
 */
export function ResponderControl({ incident }: { incident: Incident }) {
  const { data: members } = useMembers({ assignableOnly: true });
  const assign = useAssignResponder(incident.id);
  const unassign = useUnassignResponder(incident.id);
  const [error, setError] = useState<string | null>(null);

  const assigned = new Set((incident.assignees ?? []).map((assignee) => assignee.id));
  const available = (members?.data ?? []).filter(
    (member) => member.user && !assigned.has(member.user.id),
  );

  return (
    <div className="space-y-2">
      <h3 className="text-sm font-semibold">Responders</h3>

      {(incident.assignees ?? []).length === 0 ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Nobody assigned yet.</p>
      ) : (
        <ul className="space-y-1">
          {(incident.assignees ?? []).map((assignee) => (
            <li key={assignee.id} className="flex items-center gap-2 text-sm">
              <span
                aria-hidden="true"
                className="grid h-6 w-6 place-items-center rounded-full bg-slate-200 text-[10px] font-medium dark:bg-slate-700"
              >
                {initials(assignee.name)}
              </span>
              <span className="flex-1 truncate">{assignee.name}</span>
              <button
                type="button"
                onClick={() => unassign.mutate(assignee.id)}
                aria-label={`Unassign ${assignee.name}`}
                className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
              >
                <UserMinus className="h-3.5 w-3.5" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      )}

      <label htmlFor="assign-responder" className="sr-only">
        Assign a responder
      </label>
      <div className="flex gap-1">
        <select
          id="assign-responder"
          defaultValue=""
          onChange={(event) => {
            const userId = Number(event.target.value);
            if (!userId) return;

            setError(null);
            assign.mutate(userId, {
              onError: (cause) =>
                setError(cause instanceof ApiError ? cause.message : 'That responder could not be assigned.'),
            });
            event.target.value = '';
          }}
          className="flex-1 input px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"
        >
          <option value="">Assign someone…</option>
          {available.map((member) => (
            <option key={member.id} value={member.user?.id}>
              {member.user?.name} — {member.role_label}
            </option>
          ))}
        </select>
        <span className="grid w-8 place-items-center text-slate-400">
          {assign.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          ) : (
            <UserPlus className="h-4 w-4" aria-hidden="true" />
          )}
        </span>
      </div>

      {error && (
        <p role="alert" className="text-xs text-red-600 dark:text-red-400">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * Narrative updates — the communications log, distinct from the internal
 * comment thread. `public` marks an update safe to surface on a customer
 * status page, which is a different audience and a different standard of care.
 */
export function UpdatesPanel({ incidentId, canPost }: { incidentId: number; canPost: boolean }) {
  const { data } = useIncidentUpdates(incidentId);
  const postUpdate = usePostUpdate(incidentId);

  const [message, setMessage] = useState('');
  const [isPublic, setIsPublic] = useState(false);

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const trimmed = message.trim();
    if (trimmed.length < 3) return;

    postUpdate.mutate(
      { message: trimmed, public: isPublic },
      { onSuccess: () => setMessage('') },
    );
  }

  return (
    <div className="space-y-3">
      {canPost && (
        <form onSubmit={handleSubmit} className="space-y-2">
          <label htmlFor="update-message" className="block text-sm font-semibold">
            Post an update
          </label>
          <textarea
            id="update-message"
            rows={2}
            value={message}
            onChange={(event) => setMessage(event.target.value)}
            placeholder="What we know now, and what happens next."
            className="w-full input"
          />
          <div className="flex items-center justify-between">
            <label className="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-400">
              <input
                type="checkbox"
                checked={isPublic}
                onChange={(event) => setIsPublic(event.target.checked)}
                className="rounded border-slate-300"
              />
              Safe for a customer status page
            </label>
            <button
              type="submit"
              disabled={message.trim().length < 3 || postUpdate.isPending}
              className="btn btn-primary btn-sm"
            >
              Post
            </button>
          </div>
        </form>
      )}

      <ul className="space-y-2">
        {(data?.data ?? []).map((update) => (
          <li
            key={update.id}
            className="rounded-md border border-slate-200 p-3 text-sm dark:border-slate-800"
          >
            <div className="flex flex-wrap items-baseline gap-2 text-xs text-slate-500 dark:text-slate-400">
              <span className="font-medium text-slate-700 dark:text-slate-200">
                {update.author?.name ?? 'System'}
              </span>
              <span>{formatRelative(update.created_at)}</span>
              {update.is_public && (
                <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                  Public
                </span>
              )}
            </div>
            <p className="mt-1 whitespace-pre-line">{update.message}</p>
          </li>
        ))}
      </ul>

      {(data?.data ?? []).length === 0 && (
        <p className="text-sm text-slate-500 dark:text-slate-400">No updates posted yet.</p>
      )}
    </div>
  );
}
