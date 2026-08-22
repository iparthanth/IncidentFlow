import { useState, type FormEvent } from 'react';
import { CheckCircle2, FileText, Loader2, Lock } from 'lucide-react';
import {
  usePostmortem,
  usePublishPostmortem,
  useSavePostmortem,
  type PostmortemDraft,
} from '@/hooks/usePostmortem';
import { ApiError } from '@/lib/api-client';
import { formatAbsolute, titleCase } from '@/lib/format';

/**
 * Every section, and whether the publish gate cares about it.
 *
 * The four required ones are the questions a postmortem exists to answer. The
 * optional ones are genuinely optional — marking everything required would
 * just teach people to type "n/a".
 */
const SECTIONS = [
  { name: 'summary', label: 'Summary', required: true, rows: 3,
    hint: 'What happened, in the two sentences someone will actually read.' },
  { name: 'root_cause', label: 'Root cause', required: true, rows: 3,
    hint: 'The condition that made this possible — not the trigger that set it off.' },
  { name: 'impact', label: 'Impact', required: true, rows: 2,
    hint: 'Who was affected, how badly, and for how long.' },
  { name: 'resolution', label: 'Resolution', required: true, rows: 2,
    hint: 'What actually stopped it, and what stops it recurring.' },
  { name: 'contributing_factors', label: 'Contributing factors', required: false, rows: 2,
    hint: 'What made it worse or slower to find.' },
  { name: 'detection_notes', label: 'Detection', required: false, rows: 2,
    hint: 'How you found out. If it was a customer, say so.' },
  { name: 'lessons_learned', label: 'Lessons learned', required: false, rows: 3,
    hint: 'What changes as a result. Actions, not sentiments.' },
] as const;

export function PostmortemEditor({
  incidentId,
  canManage,
  canPublish,
}: {
  incidentId: number;
  canManage: boolean;
  canPublish: boolean;
}) {
  const { data: postmortem, isLoading } = usePostmortem(incidentId);
  const save = useSavePostmortem(incidentId);
  const publish = usePublishPostmortem(incidentId);

  /**
   * Local state holds only the fields the user has actually touched; every
   * other value is read straight from the server copy at render time.
   *
   * The obvious alternative — copy the server document into state in an effect
   * — means two sources of truth that have to be kept in step, and the moment
   * they drift the editor shows something nobody typed. Deriving instead means
   * a refetch or a publish is reflected immediately with no synchronisation.
   */
  const [draft, setDraft] = useState<PostmortemDraft>({});
  const [error, setError] = useState<string | null>(null);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  const valueFor = (name: (typeof SECTIONS)[number]['name']): string =>
    (draft[name] as string | undefined) ?? postmortem?.[name] ?? '';

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading postmortem…</p>;
  }

  const published = postmortem?.status === 'published';
  const editable = canManage && !published;
  const missing = postmortem?.missing_sections ?? SECTIONS.filter((s) => s.required).map((s) => s.name);

  async function handleSave(event: FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      await save.mutateAsync(
        Object.fromEntries(SECTIONS.map((section) => [section.name, valueFor(section.name)])),
      );
      // Clear the local edits so every field re-derives from the saved copy.
      setDraft({});
      setSavedAt(new Date());
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : 'The postmortem could not be saved.');
    }
  }

  async function handlePublish() {
    setError(null);
    try {
      await publish.mutateAsync();
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : 'The postmortem could not be published.');
    }
  }

  if (!postmortem && !canManage) {
    return (
      <p className="text-sm text-slate-500 dark:text-slate-400">
        No postmortem has been written for this incident yet.
      </p>
    );
  }

  return (
    <div className="space-y-4">
      {published && (
        <div className="flex items-start gap-2 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
          <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <p>
            Published {formatAbsolute(postmortem.published_at)}
            {postmortem.author?.name && ` by ${postmortem.author.name}`}. Published
            postmortems are read-only — other teams cite them, so corrections are
            amendments rather than silent rewrites.
          </p>
        </div>
      )}

      <form onSubmit={handleSave} className="space-y-4">
        {SECTIONS.map((section) => {
          const isMissing = section.required && missing.includes(section.name);

          return (
            <div key={section.name}>
              <label htmlFor={section.name} className="flex items-baseline gap-2 text-sm font-medium">
                {section.label}
                {section.required ? (
                  <span
                    className={
                      isMissing
                        ? 'text-xs font-normal text-amber-700 dark:text-amber-400'
                        : 'text-xs font-normal text-emerald-700 dark:text-emerald-400'
                    }
                  >
                    {isMissing ? 'required — blocks publishing' : 'complete'}
                  </span>
                ) : (
                  <span className="text-xs font-normal text-slate-400">optional</span>
                )}
              </label>
              <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{section.hint}</p>
              <textarea
                id={section.name}
                rows={section.rows}
                readOnly={!editable}
                value={valueFor(section.name)}
                onChange={(event) => setDraft({ ...draft, [section.name]: event.target.value })}
                className="mt-1 w-full input read-only:bg-slate-50 read-only:text-slate-600 dark:read-only:bg-slate-900"
              />
            </div>
          );
        })}

        {error && (
          <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
            {error}
          </p>
        )}

        {editable && (
          <div className="flex flex-wrap items-center gap-3">
            <button
              type="submit"
              disabled={save.isPending}
              className="btn btn-secondary"
            >
              {save.isPending && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
              {postmortem ? 'Save draft' : 'Start postmortem'}
            </button>

            {canPublish && postmortem && (
              <button
                type="button"
                onClick={() => void handlePublish()}
                // Disabled rather than hidden, with the reason spelled out
                // below: the author should see what is left, not wonder where
                // the button went.
                disabled={missing.length > 0 || publish.isPending}
                className="btn btn-primary disabled:cursor-not-allowed disabled:opacity-40"
              >
                {publish.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                ) : (
                  <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                )}
                Publish
              </button>
            )}

            {savedAt && !save.isPending && (
              <span className="text-xs text-slate-400">Saved {savedAt.toLocaleTimeString()}</span>
            )}
          </div>
        )}

        {editable && canPublish && missing.length > 0 && (
          <p className="flex items-start gap-2 rounded-md bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            <FileText className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>
              Still needed before this can be published:{' '}
              <strong>{missing.map((section) => titleCase(section)).join(', ')}</strong>. A
              postmortem with no root cause records that an incident happened and
              teaches nobody anything.
            </span>
          </p>
        )}
      </form>
    </div>
  );
}
