import { useQuery } from '@tanstack/react-query';
import { Server } from 'lucide-react';
import { request } from '@/lib/api-client';
import { paginated, ServiceSchema } from '@/lib/schemas';
import { serviceKeys } from '@/hooks/queryKeys';
import { EmptyState } from '@/components/EmptyState';

const ServiceListSchema = paginated(ServiceSchema);

const TIER_LABELS: Record<number, string> = {
  1: 'Tier 1 — customer facing',
  2: 'Tier 2 — important',
  3: 'Tier 3 — internal',
};

export function ServicesPage() {
  const { data, isLoading } = useQuery({
    queryKey: serviceKeys.list({}),
    queryFn: ({ signal }) => request('/services', ServiceListSchema, { query: { per_page: 100 }, signal }),
  });

  const services = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="page-title">Services</h1>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          The things that can break, and who owns them.
        </p>
      </div>

      {isLoading ? (
        <div aria-hidden="true" className="h-40 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" />
      ) : services.length === 0 ? (
        <EmptyState
          icon={Server}
          title="No services yet"
          description="Add the systems you operate so incidents can be attributed to them."
        />
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {services.map((service) => (
            <li
              key={service.id}
              className="card p-4"
            >
              <div className="flex items-start justify-between gap-2">
                <h2 className="text-sm font-medium">{service.name}</h2>
                {/* Count of *active* incidents, so a service that had a rough
                    quarter but is healthy today does not look on fire. */}
                {(service.open_incident_count ?? 0) > 0 && (
                  <span className="rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/50 dark:text-red-300">
                    {service.open_incident_count} open
                  </span>
                )}
              </div>

              <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {TIER_LABELS[service.tier] ?? `Tier ${service.tier}`}
              </p>

              {service.description && (
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{service.description}</p>
              )}

              {service.owner_team && (
                <p className="mt-2 text-xs text-slate-400">Owned by {service.owner_team}</p>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
