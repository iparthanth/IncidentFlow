import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Activity, Eye, EyeOff, Loader2, Lock, ShieldCheck, Timer } from 'lucide-react';
import { useAuth } from '@/auth/useAuth';
import { ApiError } from '@/lib/api-client';
import { Logo } from '@/components/Logo';

/**
 * Sign-in.
 *
 * Note what is deliberately absent: demo account credentials. They used to be
 * printed here for convenience. A password rendered on a login screen trains
 * every visitor to treat this as a toy, and there is no way for a viewer to
 * tell "seeded demo password" from "real password left in the markup". They now
 * live in the README and the setup manual, where a reader has already chosen to
 * look at project documentation.
 */
export function LoginPage() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await login(email, password);
    } catch (cause) {
      // The server deliberately returns the same message for an unknown email
      // and a wrong password; passing it straight through keeps the client from
      // reintroducing the enumeration leak the API was careful to avoid.
      setError(
        cause instanceof ApiError ? cause.message : 'Sign-in failed. Check your connection and try again.',
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-[1.1fr_1fr]">
      {/* ---------------------------------------------------------------- */}
      {/* Brand panel. Hidden below lg: on a phone it would push the actual */}
      {/* form below the fold, which is the one thing a login page must not */}
      {/* do.                                                              */}
      {/* ---------------------------------------------------------------- */}
      <aside className="relative hidden overflow-hidden bg-brand-900 p-12 lg:flex lg:flex-col lg:justify-between">
        {/* Depth without imagery — two soft radial washes over the brand colour. */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 opacity-70"
          style={{
            background:
              'radial-gradient(60rem 40rem at 10% -10%, var(--color-brand-600), transparent 60%),' +
              'radial-gradient(40rem 40rem at 90% 110%, var(--color-brand-700), transparent 55%)',
          }}
        />

        <div className="relative">
          <span className="inline-flex items-center gap-3">
            <Logo className="h-10 w-10" />
            <span className="text-xl font-semibold tracking-tight text-white">IncidentFlow</span>
          </span>
        </div>

        <div className="relative max-w-md">
          <h2 className="text-3xl leading-tight font-semibold tracking-tight text-white">
            Every incident, on the record.
          </h2>
          <p className="mt-4 text-brand-100/90">
            Report, coordinate and review production incidents — with a timeline nobody can
            rewrite after the fact.
          </p>

          <ul className="mt-10 space-y-5">
            {[
              { icon: Activity, title: 'Live coordination', body: 'Updates reach every open screen without a refresh.' },
              { icon: Timer, title: 'Measured response', body: 'Time to acknowledge and resolve, tracked per severity.' },
              { icon: ShieldCheck, title: 'Immutable history', body: 'Append-only timelines and a permanent audit log.' },
            ].map(({ icon: Icon, title, body }) => (
              <li key={title} className="flex gap-3.5">
                <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/10 ring-1 ring-white/15">
                  <Icon className="h-4.5 w-4.5 text-white" aria-hidden="true" />
                </span>
                <span>
                  <span className="block text-sm font-medium text-white">{title}</span>
                  <span className="mt-0.5 block text-sm text-brand-100/75">{body}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>

        <p className="relative text-xs text-brand-200/60">
          Modular service-oriented architecture · Laravel · React · Redis
        </p>
      </aside>

      {/* ---------------------------------------------------------------- */}
      {/* Form panel                                                        */}
      {/* ---------------------------------------------------------------- */}
      <main className="flex items-center justify-center bg-slate-50 px-6 py-12 dark:bg-slate-950">
        <div className="w-full max-w-sm">
          {/* The mark repeats here only where the brand panel is hidden. */}
          <div className="mb-8 flex justify-center lg:hidden">
            <span className="inline-flex items-center gap-2.5">
              <Logo className="h-9 w-9" />
              <span className="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-50">
                IncidentFlow
              </span>
            </span>
          </div>

          <div className="mb-7">
            <h1 className="page-title">Sign in</h1>
            <p className="page-subtitle">Access your organization&rsquo;s incident workspace.</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5" noValidate>
            <div>
              <label htmlFor="email" className="label">
                Work email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="username"
                required
                autoFocus
                placeholder="you@company.com"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="input mt-1.5"
              />
            </div>

            <div>
              <label htmlFor="password" className="label">
                Password
              </label>
              <div className="relative mt-1.5">
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  className="input pr-11"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((visible) => !visible)}
                  // Typos are invisible in a masked field and the error message
                  // is deliberately unhelpful, so a reveal toggle is the only
                  // way to tell a wrong password from a mistyped one.
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  className="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-lg text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-300"
                >
                  {showPassword ? (
                    <EyeOff className="h-4 w-4" aria-hidden="true" />
                  ) : (
                    <Eye className="h-4 w-4" aria-hidden="true" />
                  )}
                </button>
              </div>
            </div>

            {error && (
              <p role="alert" className="alert alert-error">
                <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{error}</span>
              </p>
            )}

            <button type="submit" disabled={submitting} className="btn btn-primary w-full">
              {submitting && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
              {submitting ? 'Signing in…' : 'Sign in'}
            </button>
          </form>

          <p className="mt-8 border-t border-slate-200 pt-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
            Don&rsquo;t have an organization?{' '}
            <Link
              to="/register"
              className="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
            >
              Create one
            </Link>
          </p>
        </div>
      </main>
    </div>
  );
}
