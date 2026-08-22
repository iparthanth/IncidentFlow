import { NavLink, Outlet } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, BarChart3, FileText, LogOut, Server, Shield, Wifi, WifiOff } from 'lucide-react';
import { useAuth } from '@/auth/useAuth';
import { useIncidentStream } from '@/realtime/useIncidentStream';
import { initials } from '@/lib/format';
import { Logo } from '@/components/Logo';

const NAVIGATION = [
  { to: '/incidents', label: 'Incidents', icon: AlertTriangle, permission: 'incident.view' },
  { to: '/services', label: 'Services', icon: Server, permission: 'service.view' },
  { to: '/metrics', label: 'Metrics', icon: BarChart3, permission: 'metrics.view' },
  { to: '/postmortems', label: 'Postmortems', icon: FileText, permission: 'postmortem.view' },
  { to: '/admin', label: 'Admin', icon: Shield, permission: 'audit.view' },
] as const;

export function AppLayout() {
  const { user, organization, memberships, can, logout, switchOrganization } = useAuth();

  // One stream for the whole session, opened here rather than per page — a
  // navigation between screens must not tear down and rebuild the connection.
  const { status: streamStatus, lastEventAt } = useIncidentStream();

  return (
    <div className="min-h-full bg-slate-50 dark:bg-slate-950">
      <a href="#main" className="skip-link">
        Skip to main content
      </a>

      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/80 backdrop-blur-md dark:border-slate-800 dark:bg-slate-900/80">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:px-6">
          <Logo className="h-7 w-7 shrink-0" />
          <span className="text-[15px] font-semibold tracking-tight text-slate-900 dark:text-slate-50">
            Incident<span className="text-brand-600 dark:text-brand-400">Flow</span>
          </span>

          <span aria-hidden="true" className="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700" />

          {memberships.length > 1 ? (
            <label className="hidden sm:block">
              <span className="sr-only">Organization</span>
              <select
                value={organization?.slug ?? ''}
                onChange={(event) => switchOrganization(event.target.value)}
                className="rounded-lg bg-transparent px-2 py-1 text-sm font-medium text-slate-700 ring-1 ring-slate-200 ring-inset transition-colors hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800"
              >
                {memberships.map((membership) => (
                  <option key={membership.organization.id} value={membership.organization.slug}>
                    {membership.organization.name}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <span className="hidden truncate text-sm font-medium text-slate-600 sm:block dark:text-slate-300">
              {organization?.name ?? '—'}
            </span>
          )}

          <div className="ml-auto flex items-center gap-2 sm:gap-3">
            <StreamIndicator status={streamStatus} lastEventAt={lastEventAt} />

            <span aria-hidden="true" className="hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700" />

            <div className="flex items-center gap-2">
              <span
                aria-hidden="true"
                className="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 dark:bg-brand-900/60 dark:text-brand-200 dark:ring-brand-800"
              >
                {initials(user?.name)}
              </span>
              <span className="hidden text-sm font-medium text-slate-700 md:inline dark:text-slate-200">
                {user?.name}
              </span>
            </div>

            <button
              type="button"
              onClick={() => void logout()}
              className="btn btn-ghost btn-sm"
              title="Sign out"
            >
              <LogOut className="h-4 w-4" aria-hidden="true" />
              <span className="hidden sm:inline">Sign out</span>
            </button>
          </div>
        </div>

        <nav aria-label="Primary" className="mx-auto max-w-7xl px-4 sm:px-6">
          <ul className="-mb-px flex gap-1 overflow-x-auto">
            {NAVIGATION.filter((item) => can(item.permission)).map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  className={({ isActive }) =>
                    clsx(
                      'flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm whitespace-nowrap transition-colors',
                      isActive
                        ? 'border-brand-600 font-semibold text-brand-700 dark:border-brand-400 dark:text-brand-300'
                        : 'border-transparent font-medium text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:border-slate-700 dark:hover:text-slate-200',
                    )
                  }
                >
                  <item.icon className="h-4 w-4" aria-hidden="true" />
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      </header>

      <main id="main" className="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <Outlet />
      </main>
    </div>
  );
}

/**
 * Live-connection indicator.
 *
 * Not decoration. When the stream drops, the incident list stops updating on
 * its own — and a responder who believes they are watching a live feed while
 * staring at a five-minute-old page will make a decision on stale information.
 * Saying so plainly is the honest thing to do.
 */
function StreamIndicator({
  status,
  lastEventAt,
}: {
  status: 'connecting' | 'live' | 'reconnecting' | 'offline';
  lastEventAt: Date | null;
}) {
  const isLive = status === 'live';

  return (
    <span
      role="status"
      aria-live="polite"
      title={lastEventAt ? `Last update ${lastEventAt.toLocaleTimeString()}` : 'No updates yet'}
      className={clsx(
        'flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset',
        isLive
          ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900'
          : 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-900',
      )}
    >
      {isLive ? (
        <>
          {/* A quiet pulse: the only animation in the header, and it stops the
              moment the connection does. */}
          <span className="relative flex h-2 w-2" aria-hidden="true">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
          </span>
          <Wifi className="hidden h-3.5 w-3.5 sm:inline" aria-hidden="true" />
        </>
      ) : (
        <WifiOff className="h-3.5 w-3.5" aria-hidden="true" />
      )}
      <span className="hidden sm:inline">
        {isLive ? 'Live' : status === 'reconnecting' ? 'Reconnecting…' : 'Not live'}
      </span>
    </span>
  );
}
