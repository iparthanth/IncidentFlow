import clsx from 'clsx';
import { formatTimestamp } from '@/lib/format';
import type { IncidentEvent } from '@/lib/schemas';

/**
 * The incident timeline.
 *
 * Rendered as an ordered list because it is one — the sequence carries meaning,
 * and a screen reader user should hear "3 of 11" rather than a wall of
 * paragraphs. Each entry shows both a relative and an absolute time: relative
 * for the person reading during the incident, absolute for the person reading
 * the postmortem three weeks later.
 */
export function Timeline({ events }: { events: IncidentEvent[] }) {
  if (events.length === 0) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">No activity recorded yet.</p>;
  }

  return (
    <ol className="relative space-y-4 border-l border-slate-200 pl-6 dark:border-slate-700">
      {events.map((event) => {
        const { relative, absolute } = formatTimestamp(event.occurred_at);

        return (
          <li key={event.id} className="relative">
            <span
              aria-hidden="true"
              className={clsx(
                'absolute -left-[1.6875rem] top-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-white dark:ring-slate-900',
                dotColour(event.type),
              )}
            />

            <div className="flex flex-wrap items-baseline gap-x-2">
              <p className="text-sm text-slate-800 dark:text-slate-200">{event.summary}</p>
              <time
                dateTime={event.occurred_at ?? undefined}
                title={absolute}
                className="text-xs text-slate-400 dark:text-slate-500"
              >
                {relative}
              </time>
            </div>

            {typeof event.payload.note === 'string' && event.payload.note.length > 0 && (
              <blockquote className="mt-1 border-l-2 border-slate-200 pl-3 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-400">
                {event.payload.note}
              </blockquote>
            )}

            {/* The correlation id is shown on the entries most likely to be
                investigated later, so an engineer can jump straight from the
                timeline to the matching server logs. */}
            {event.request_id && (
              <p className="mt-1 font-mono text-[10px] text-slate-300 dark:text-slate-600">
                {event.request_id}
              </p>
            )}
          </li>
        );
      })}
    </ol>
  );
}

function dotColour(type: string): string {
  if (type.includes('resolved')) return 'bg-emerald-500';
  if (type.includes('closed')) return 'bg-slate-400';
  if (type.includes('reopened') || type.includes('severity')) return 'bg-orange-500';
  if (type.includes('created')) return 'bg-red-500';
  if (type.includes('mitigated')) return 'bg-sky-500';
  return 'bg-slate-300 dark:bg-slate-600';
}
