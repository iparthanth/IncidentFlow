import clsx from 'clsx';
import type { Severity, Status } from '@/lib/schemas';

/**
 * Severity and status badges.
 *
 * Colour is never the only signal. Roughly one in twelve men has some form of
 * colour vision deficiency, and "the red one" is a common way to describe a
 * SEV-1 — so each badge also carries its own text, and severity carries a
 * shape cue in the border weight. An incident tool that can only be read in
 * full colour is an incident tool that fails the person on call tonight.
 */

const SEVERITY_STYLES: Record<Severity, string> = {
  sev1: 'bg-red-50 text-red-800 ring-red-600/30 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-400/30',
  sev2: 'bg-orange-50 text-orange-800 ring-orange-600/30 dark:bg-orange-950/50 dark:text-orange-300 dark:ring-orange-400/30',
  sev3: 'bg-amber-50 text-amber-800 ring-amber-600/30 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-400/30',
  sev4: 'bg-slate-100 text-slate-700 ring-slate-500/30 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-400/30',
};

const STATUS_STYLES: Record<Status, string> = {
  open: 'bg-red-50 text-red-800 ring-red-600/30 dark:bg-red-950/50 dark:text-red-300 dark:ring-red-400/30',
  acknowledged: 'bg-orange-50 text-orange-800 ring-orange-600/30 dark:bg-orange-950/50 dark:text-orange-300 dark:ring-orange-400/30',
  mitigated: 'bg-sky-50 text-sky-800 ring-sky-600/30 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-400/30',
  resolved: 'bg-emerald-50 text-emerald-800 ring-emerald-600/30 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-400/30',
  closed: 'bg-slate-100 text-slate-600 ring-slate-500/30 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-400/30',
};

const BASE = 'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap';

export function SeverityBadge({ severity, label }: { severity: Severity; label?: string }) {
  return (
    <span
      className={clsx(BASE, SEVERITY_STYLES[severity], severity === 'sev1' && 'ring-2 font-semibold')}
      // The visible text is abbreviated for density; the accessible name is not.
      title={label ?? severity.toUpperCase()}
    >
      {severity.toUpperCase().replace('SEV', 'SEV-')}
    </span>
  );
}

export function StatusBadge({ status, label }: { status: Status; label?: string }) {
  return (
    <span className={clsx(BASE, STATUS_STYLES[status])}>
      <span
        aria-hidden="true"
        className={clsx(
          'h-1.5 w-1.5 rounded-full bg-current',
          // A pulse marks the states that still need a human. Suppressed under
          // prefers-reduced-motion by the global rule in index.css.
          (status === 'open' || status === 'acknowledged') && 'animate-pulse',
        )}
      />
      {label ?? status}
    </span>
  );
}
