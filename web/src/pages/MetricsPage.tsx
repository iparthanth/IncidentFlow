import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { request } from '@/lib/api-client';
import { MetricsSummarySchema, TrendPointSchema, wrapped } from '@/lib/schemas';
import { metricsKeys } from '@/hooks/queryKeys';
import { formatDuration, formatNumber, formatPercent } from '@/lib/format';
import { z } from 'zod';

const SummarySchema = wrapped(MetricsSummarySchema);
const TrendsSchema = wrapped(
  z.object({
    period: z.object({ from: z.string(), to: z.string() }),
    series: z.array(TrendPointSchema),
  }),
);

const WINDOWS = [7, 30, 90] as const;

export function MetricsPage() {
  const [days, setDays] = useState<number>(30);

  const { data: summary } = useQuery({
    queryKey: metricsKeys.summary(days),
    queryFn: ({ signal }) => request('/metrics/summary', SummarySchema, { query: { days }, signal }),
  });

  const { data: trends } = useQuery({
    queryKey: metricsKeys.trends(days),
    queryFn: ({ signal }) => request('/metrics/trends', TrendsSchema, { query: { days }, signal }),
  });

  const mtta = summary?.data.mtta_seconds;
  const mttr = summary?.data.mttr_seconds;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="page-title">Reliability metrics</h1>

        <div role="group" aria-label="Reporting window" className="flex gap-1">
          {WINDOWS.map((window) => (
            <button
              key={window}
              type="button"
              onClick={() => setDays(window)}
              aria-pressed={days === window}
              className={
                days === window
                  ? 'btn btn-primary btn-sm'
                  : 'input px-3 py-1.5 text-sm dark:border-slate-700'
              }
            >
              {window}d
            </button>
          ))}
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Incidents opened" value={formatNumber(summary?.data.totals.created)} />
        <Stat label="Currently open" value={formatNumber(summary?.data.totals.currently_open)} emphasis />
        <Stat
          label="Mean time to acknowledge"
          value={formatDuration(mtta?.average)}
          hint={`p50 ${formatDuration(mtta?.p50)} · p95 ${formatDuration(mtta?.p95)}`}
        />
        <Stat
          label="Mean time to resolve"
          value={formatDuration(mttr?.average)}
          hint={`p50 ${formatDuration(mttr?.p50)} · p95 ${formatDuration(mttr?.p95)}`}
        />
      </div>

      {/* The mean is what people quote; the percentiles are what tell them
          whether it means anything. Both are shown together, always. */}
      <p className="text-xs text-slate-500 dark:text-slate-400">
        Averages are shown alongside p50 and p95 deliberately — a twelve-minute
        mean with a two-hour p95 describes a very different on-call experience
        from a twelve-minute mean with a fifteen-minute p95.
      </p>

      <section aria-labelledby="sla-heading" className="card p-4">
        <h2 id="sla-heading" className="text-sm font-semibold">
          Acknowledgement SLA attainment
        </h2>
        <p className="mt-1 text-2xl font-semibold tabular-nums">
          {formatPercent(summary?.data.acknowledgement_sla.overall_attainment)}
        </p>

        <table className="mt-4 min-w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-slate-500 dark:text-slate-400">
              <th scope="col" className="py-1">Severity</th>
              <th scope="col" className="py-1">Target</th>
              <th scope="col" className="py-1">Within target</th>
              <th scope="col" className="py-1">Attainment</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(summary?.data.acknowledgement_sla.by_severity ?? {}).map(([severity, row]) => (
              <tr key={severity} className="border-t border-slate-100 dark:border-slate-800">
                <td className="py-1.5 uppercase">{severity.replace('sev', 'SEV-')}</td>
                <td className="py-1.5 tabular-nums">{row.target_minutes}m</td>
                <td className="py-1.5 tabular-nums">
                  {row.within_target}/{row.total}
                </td>
                <td className="py-1.5 tabular-nums">{formatPercent(row.attainment)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section aria-labelledby="throughput-heading" className="card p-4">
        <h2 id="throughput-heading" className="mb-3 text-sm font-semibold">
          Opened vs resolved
        </h2>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={trends?.data.series ?? []}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-700" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
              <Tooltip />
              <Legend />
              <Bar dataKey="created" name="Opened" fill="#dc2626" radius={[2, 2, 0, 0]} />
              <Bar dataKey="resolved" name="Resolved" fill="#059669" radius={[2, 2, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </section>

      <section aria-labelledby="mttr-heading" className="card p-4">
        <h2 id="mttr-heading" className="mb-3 text-sm font-semibold">
          Daily mean time to resolve
        </h2>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={trends?.data.series ?? []}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-700" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis
                tick={{ fontSize: 11 }}
                // Seconds on the axis would be unreadable; minutes are what a
                // reader actually thinks in.
                tickFormatter={(value: number) => `${Math.round(value / 60)}m`}
              />
              {/* Recharts hands the formatter a loose ValueType, so the number
                  is narrowed here rather than asserted away. */}
              <Tooltip formatter={(value) => formatDuration(typeof value === 'number' ? value : null)} />
              <Line
                type="monotone"
                dataKey="mttr_seconds"
                name="MTTR"
                stroke="#0284c7"
                strokeWidth={2}
                dot={false}
                // Days with no resolutions have no value; drawing a zero would
                // claim an instant recovery that never happened.
                connectNulls
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </section>
    </div>
  );
}

function Stat({
  label,
  value,
  hint,
  emphasis,
}: {
  label: string;
  value: string;
  hint?: string;
  emphasis?: boolean;
}) {
  return (
    <div className="card p-4">
      <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
      <p
        className={
          emphasis
            ? 'mt-1 text-2xl font-semibold tabular-nums text-red-600 dark:text-red-400'
            : 'mt-1 text-2xl font-semibold tabular-nums'
        }
      >
        {value}
      </p>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}
