import { Loader2 } from 'lucide-react';

/**
 * `role="status"` with `aria-live="polite"` so a screen reader announces the
 * wait instead of landing on a page that appears to be empty.
 */
export function FullPageSpinner({ label = 'Loading…' }: { label?: string }) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex min-h-screen flex-col items-center justify-center gap-3 text-slate-500 dark:text-slate-400"
    >
      <Loader2 className="h-6 w-6 animate-spin" aria-hidden="true" />
      <p className="text-sm">{label}</p>
    </div>
  );
}
