import { Component, type ErrorInfo, type ReactNode } from 'react';
import { ApiError } from '@/lib/api-client';

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * Last line of defence against a white screen.
 *
 * A crash in an incident dashboard is worse than a crash almost anywhere else:
 * it happens while something is already broken, and the person looking at it
 * has no patience for a blank page. So the fallback shows what failed, offers
 * one recovery action, and — critically — surfaces the request id, which is
 * the only thing that lets an engineer find the matching server-side logs.
 */
export class ErrorBoundary extends Component<Props, State> {
  override state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    // In production this is where a Sentry (or equivalent) capture belongs.
    // Logging to the console at minimum means the trace is recoverable from a
    // user's own devtools when they report it.
    console.error('Unhandled UI error', error, info.componentStack);
  }

  private reset = (): void => {
    this.setState({ error: null });
  };

  override render(): ReactNode {
    const { error } = this.state;

    if (!error) return this.props.children;

    const requestId = error instanceof ApiError ? error.requestId : null;

    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div
          role="alert"
          className="w-full max-w-lg rounded-xl border border-red-200 bg-white p-6 shadow-sm dark:border-red-900 dark:bg-slate-900"
        >
          <h1 className="text-lg font-semibold text-red-700 dark:text-red-400">
            Something went wrong
          </h1>

          <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
            {error instanceof ApiError
              ? error.message
              : 'The page failed to render. Reloading usually clears it.'}
          </p>

          {requestId && (
            <p className="mt-4 rounded-md bg-slate-50 p-3 font-mono text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              Request ID: {requestId}
            </p>
          )}

          <div className="mt-6 flex gap-3">
            <button
              type="button"
              onClick={this.reset}
              className="btn btn-primary"
            >
              Try again
            </button>
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="btn btn-secondary"
            >
              Reload the page
            </button>
          </div>
        </div>
      </div>
    );
  }
}
