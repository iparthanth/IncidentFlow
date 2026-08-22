import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Download, Loader2, Plus, Search } from 'lucide-react';
import { useAuth } from '@/auth/useAuth';
import { useIncidents, type IncidentFilters } from '@/hooks/useIncidents';
import { ApiError, download } from '@/lib/api-client';
import { SeverityBadge, StatusBadge } from '@/components/Badges';
import { EmptyState } from '@/components/EmptyState';
import { NewIncidentDialog } from '@/components/NewIncidentDialog';
import { formatDuration, formatRelative } from '@/lib/format';
import { SEVERITIES, STATUSES } from '@/lib/schemas';

export function IncidentsPage() {
  const { can } = useAuth();
  const [filters, setFilters] = useState<IncidentFilters>({
    per_page: 25,
    page: 1,
    sort: 'created_at',
    direction: 'desc',
  });
  const [creating, setCreating] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const { data, isLoading, isError, error } = useIncidents(filters);
  const incidents = data?.data ?? [];

  function update(patch: Partial<IncidentFilters>) {
    // Any filter change resets to page one; keeping the old page number would
    // routinely land the user on an empty page.
    setFilters((current) => ({ ...current, ...patch, page: 1 }));
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="page-title">Incidents</h1>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            {data?.meta?.total ?? 0} matching this filter
          </p>
        </div>

        <div className="flex items-center gap-2">
          {/* Exports honour the filters on screen, so what you see is what you
              get — an export that silently ignored them would be a trap. */}
          {can('export.run') && (
            <button
              type="button"
              disabled={exporting}
              onClick={() => {
                setExportError(null);
                setExporting(true);
                download('/incidents/export', { query: filters as Record<string, unknown> })
                  .catch((cause) =>
                    setExportError(
                      cause instanceof ApiError ? cause.message : 'The export could not be generated.',
                    ),
                  )
                  .finally(() => setExporting(false));
              }}
              className="btn btn-secondary"
            >
              {exporting ? (
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              ) : (
                <Download className="h-4 w-4" aria-hidden="true" />
              )}
              Export CSV
            </button>
          )}

          {can('incident.create') && (
            <button
              type="button"
              onClick={() => setCreating(true)}
              className="btn btn-primary"
            >
              <Plus className="h-4 w-4" aria-hidden="true" />
              Report incident
            </button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-2 card p-3">
        <div className="relative min-w-56 flex-1">
          <Search
            className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400"
            aria-hidden="true"
          />
          <label htmlFor="incident-search" className="sr-only">
            Search incidents
          </label>
          <input
            id="incident-search"
            type="search"
            placeholder="Search title, reference or description…"
            defaultValue={filters.q ?? ''}
            onChange={(event) => update({ q: event.target.value })}
            className="w-full input py-2 pr-3 pl-9"
          />
        </div>

        <label className="text-sm">
          <span className="sr-only">Filter by status</span>
          <select
            value={filters.status?.[0] ?? ''}
            onChange={(event) =>
              update({ status: event.target.value ? [event.target.value as never] : undefined })
            }
            className="input px-2"
          >
            <option value="">All statuses</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>
                {status}
              </option>
            ))}
          </select>
        </label>

        <label className="text-sm">
          <span className="sr-only">Filter by severity</span>
          <select
            value={filters.severity?.[0] ?? ''}
            onChange={(event) =>
              update({ severity: event.target.value ? [event.target.value] : undefined })
            }
            className="input px-2"
          >
            <option value="">All severities</option>
            {SEVERITIES.map((severity) => (
              <option key={severity} value={severity}>
                {severity.toUpperCase()}
              </option>
            ))}
          </select>
        </label>

        <label className="flex items-center gap-1.5 text-sm">
          <input
            type="checkbox"
            checked={Boolean(filters.active_only)}
            onChange={(event) => update({ active_only: event.target.checked || undefined })}
            className="rounded border-slate-300"
          />
          Active only
        </label>
      </div>

      {exportError && (
        <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
          {exportError}
        </p>
      )}

      {isError && (
        <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
          {error instanceof Error ? error.message : 'Could not load incidents.'}
        </p>
      )}

      {isLoading ? (
        <IncidentTableSkeleton />
      ) : incidents.length === 0 ? (
        <EmptyState
          icon={AlertTriangle}
          title="No incidents match"
          description="Either everything is healthy, or the filters are too narrow."
        />
      ) : (
        <div className="overflow-x-auto card">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <caption className="sr-only">Incidents matching the current filters</caption>
            <thead className="bg-slate-50 dark:bg-slate-800/50">
              <tr>
                <th scope="col" className="px-3 py-2 text-left font-medium">Reference</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Severity</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Status</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Title</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Service</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Opened</th>
                <th scope="col" className="px-3 py-2 text-left font-medium">Running / resolved in</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {incidents.map((incident) => (
                <tr key={incident.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link to={`/incidents/${incident.id}`} className="font-medium underline-offset-2 hover:underline">
                      {incident.reference}
                    </Link>
                  </td>
                  <td className="px-3 py-2">
                    <SeverityBadge severity={incident.severity.value} label={incident.severity.label} />
                  </td>
                  <td className="px-3 py-2">
                    <StatusBadge status={incident.status.value} label={incident.status.label} />
                  </td>
                  <td className="max-w-md truncate px-3 py-2">{incident.title}</td>
                  <td className="px-3 py-2 text-slate-500 dark:text-slate-400">
                    {incident.service?.name ?? '—'}
                  </td>
                  <td className="px-3 py-2 text-slate-500 dark:text-slate-400">
                    {formatRelative(incident.timestamps.created_at)}
                  </td>
                  <td className="px-3 py-2 tabular-nums text-slate-500 dark:text-slate-400">
                    {formatDuration(
                      incident.durations.open_for_seconds ?? incident.durations.time_to_resolve_seconds,
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(data?.meta?.last_page ?? 1) > 1 && (
        <nav aria-label="Pagination" className="flex items-center justify-between text-sm">
          <button
            type="button"
            disabled={(filters.page ?? 1) <= 1}
            onClick={() => setFilters((current) => ({ ...current, page: (current.page ?? 1) - 1 }))}
            className="input px-3 py-1.5 disabled:opacity-40 dark:border-slate-700"
          >
            Previous
          </button>
          <span className="text-slate-500 dark:text-slate-400">
            Page {data?.meta?.current_page ?? 1} of {data?.meta?.last_page ?? 1}
          </span>
          <button
            type="button"
            disabled={(data?.meta?.current_page ?? 1) >= (data?.meta?.last_page ?? 1)}
            onClick={() => setFilters((current) => ({ ...current, page: (current.page ?? 1) + 1 }))}
            className="input px-3 py-1.5 disabled:opacity-40 dark:border-slate-700"
          >
            Next
          </button>
        </nav>
      )}

      {creating && <NewIncidentDialog onClose={() => setCreating(false)} />}
    </div>
  );
}

function IncidentTableSkeleton() {
  return (
    <div aria-hidden="true" className="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-800">
      {Array.from({ length: 6 }).map((_, index) => (
        <div key={index} className="h-8 animate-pulse rounded bg-slate-100 dark:bg-slate-800" />
      ))}
    </div>
  );
}
