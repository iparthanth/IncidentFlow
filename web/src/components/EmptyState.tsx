import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
      <Icon className="mx-auto h-8 w-8 text-slate-400" aria-hidden="true" />
      <h2 className="mt-3 text-sm font-medium text-slate-900 dark:text-slate-100">{title}</h2>
      <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{description}</p>
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
