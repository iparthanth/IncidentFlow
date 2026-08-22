import { createContext, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { z } from 'zod';
import {
  ApiError,
  refreshAccessToken,
  request,
  requestNoContent,
  setAccessToken,
  setOrganization,
} from '@/lib/api-client';
import { OrganizationSchema, UserSchema, wrapped } from '@/lib/schemas';
import type { Organization, User } from '@/lib/schemas';

const SessionSchema = wrapped(
  z.object({
    user: UserSchema,
    access_token: z.string(),
    token_type: z.string(),
    expires_in: z.number().int(),
    expires_at: z.string(),
    organization: OrganizationSchema.optional(),
  }),
);

const MembershipSchema = wrapped(
  z.object({
    user: UserSchema,
    organizations: z.array(
      z.object({
        organization: OrganizationSchema,
        role: z.string(),
        permissions: z.array(z.string()),
      }),
    ),
  }),
);

export interface Membership {
  organization: Organization;
  role: string;
  permissions: string[];
}

export interface AuthState {
  status: 'loading' | 'authenticated' | 'anonymous';
  user: User | null;
  memberships: Membership[];
  organization: Organization | null;
  permissions: Set<string>;
  can: (permission: string) => boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  logout: () => Promise<void>;
  switchOrganization: (slug: string) => void;
}

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  organization_name: string;
}

export const AuthContext = createContext<AuthState | null>(null);

/** Remembers the last tenant across reloads. Not a credential — just a preference. */
const ORG_STORAGE_KEY = 'incidentflow.organization';

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthState['status']>('loading');
  const [user, setUser] = useState<User | null>(null);
  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [organizationSlug, setOrganizationSlug] = useState<string | null>(
    () => localStorage.getItem(ORG_STORAGE_KEY),
  );

  const refreshTimer = useRef<number | null>(null);

  const loadProfile = useCallback(async () => {
    const profile = await request('/auth/me', MembershipSchema);
    setUser(profile.data.user);
    setMemberships(profile.data.organizations);

    // Fall back to the first membership when the remembered tenant is gone —
    // being removed from an organization should not strand the user on a
    // permanently failing screen.
    const slugs = profile.data.organizations.map((m) => m.organization.slug);
    const remembered = localStorage.getItem(ORG_STORAGE_KEY);
    const active = remembered && slugs.includes(remembered) ? remembered : (slugs[0] ?? null);

    setOrganizationSlug(active);
    setOrganization(active);
    if (active) localStorage.setItem(ORG_STORAGE_KEY, active);

    return profile.data.user;
  }, []);

  /**
   * Refresh slightly before expiry rather than waiting for a 401.
   *
   * Reactive refresh works, but it means the *first* action after an idle
   * period always pays a double round-trip — and during an incident that
   * action is usually someone trying to acknowledge a page. Refreshing at 80%
   * of the token's life keeps the credential warm at no visible cost.
   */
  /**
   * The loop is held in a ref so it can re-arm itself.
   *
   * A `useCallback` that calls itself by name closes over the binding from the
   * render that created it, which means the recursion would keep invoking a
   * stale copy forever. Going through a ref keeps one live function and makes
   * the self-reference legal rather than merely lucky.
   */
  const scheduleRefreshRef = useRef<(expiresInSeconds: number) => void>(() => {});

  useEffect(() => {
    scheduleRefreshRef.current = (expiresInSeconds: number): void => {
      if (refreshTimer.current !== null) window.clearTimeout(refreshTimer.current);

      const delay = Math.max(30_000, expiresInSeconds * 1000 * 0.8);
      refreshTimer.current = window.setTimeout(() => {
        void refreshAccessToken().then((token) => {
          if (token) scheduleRefreshRef.current(expiresInSeconds);
        });
      }, delay);
    };
  }, []);

  const scheduleRefresh = useCallback((expiresInSeconds: number): void => {
    scheduleRefreshRef.current(expiresInSeconds);
  }, []);

  // Restore the session on load. The refresh cookie is the only thing that
  // survives a reload, so this is what makes "stay signed in" work without
  // ever putting a long-lived credential somewhere JavaScript can read it.
  useEffect(() => {
    let cancelled = false;

    void (async () => {
      const token = await refreshAccessToken();

      if (cancelled) return;

      if (!token) {
        setStatus('anonymous');
        return;
      }

      try {
        await loadProfile();
        if (!cancelled) setStatus('authenticated');
      } catch {
        if (!cancelled) {
          setAccessToken(null);
          setStatus('anonymous');
        }
      }
    })();

    return () => {
      cancelled = true;
      if (refreshTimer.current !== null) window.clearTimeout(refreshTimer.current);
    };
  }, [loadProfile]);

  const login = useCallback(
    async (email: string, password: string) => {
      const session = await request('/auth/login', SessionSchema, {
        method: 'POST',
        body: { email, password },
        skipRefresh: true,
        idempotent: false,
      });

      setAccessToken(session.data.access_token);
      await loadProfile();
      scheduleRefresh(session.data.expires_in);
      setStatus('authenticated');
    },
    [loadProfile, scheduleRefresh],
  );

  const register = useCallback(
    async (input: RegisterInput) => {
      const session = await request('/auth/register', SessionSchema, {
        method: 'POST',
        body: input,
        skipRefresh: true,
        idempotent: false,
      });

      setAccessToken(session.data.access_token);
      await loadProfile();
      scheduleRefresh(session.data.expires_in);
      setStatus('authenticated');
    },
    [loadProfile, scheduleRefresh],
  );

  const logout = useCallback(async () => {
    try {
      await requestNoContent('/auth/logout', { method: 'POST', idempotent: false });
    } catch (error) {
      // A failed logout call must still clear local state: leaving the user
      // apparently signed in because the network blipped is the worse outcome.
      if (!(error instanceof ApiError)) throw error;
    } finally {
      setAccessToken(null);
      setUser(null);
      setMemberships([]);
      setStatus('anonymous');
      if (refreshTimer.current !== null) window.clearTimeout(refreshTimer.current);
    }
  }, []);

  const switchOrganization = useCallback((slug: string) => {
    setOrganizationSlug(slug);
    setOrganization(slug);
    localStorage.setItem(ORG_STORAGE_KEY, slug);
    // A full reload is the honest way to drop every cached query belonging to
    // the previous tenant. Selectively invalidating risks one stale list
    // rendering another organization's incidents.
    window.location.reload();
  }, []);

  const value = useMemo<AuthState>(() => {
    const active = memberships.find((m) => m.organization.slug === organizationSlug) ?? memberships[0] ?? null;
    const permissions = new Set(active?.permissions ?? []);

    return {
      status,
      user,
      memberships,
      organization: active?.organization ?? null,
      permissions,
      can: (permission: string) => permissions.has(permission),
      login,
      register,
      logout,
      switchOrganization,
    };
  }, [status, user, memberships, organizationSlug, login, register, logout, switchOrganization]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
