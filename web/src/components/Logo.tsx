/**
 * The IncidentFlow mark.
 *
 * A pulse line inside a rounded tile. Deliberately *not* a warning triangle:
 * the triangle is used throughout the app to mean "incident", and a brand that
 * borrows a semantic icon teaches people to stop reading it as one.
 *
 * The gradient is defined with a unique id per instance because two inline SVGs
 * sharing a gradient id in one document is a genuine rendering bug — the second
 * silently adopts the first one's stops.
 */
import { useId } from 'react';

export function Logo({ className = 'h-8 w-8' }: { className?: string }) {
  const gradientId = useId();

  return (
    <svg
      viewBox="0 0 32 32"
      className={className}
      role="img"
      aria-label="IncidentFlow"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
          <stop stopColor="var(--color-brand-400)" />
          <stop offset="1" stopColor="var(--color-brand-700)" />
        </linearGradient>
      </defs>

      <rect width="32" height="32" rx="8.5" fill={`url(#${gradientId})`} />

      {/* The pulse: flat, spike, flat — an incident against a steady baseline. */}
      <path
        d="M6 19.5h4.2l2.4-7 3.2 11 2.6-8 1.9 4h5.7"
        stroke="white"
        strokeWidth="2.1"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/** The mark plus the word, for headers and the sign-in screen. */
export function Wordmark({ className = '' }: { className?: string }) {
  return (
    <span className={`inline-flex items-center gap-2.5 ${className}`}>
      <Logo className="h-7 w-7" />
      <span className="text-base font-semibold tracking-tight text-slate-900 dark:text-slate-50">
        Incident<span className="text-brand-600 dark:text-brand-400">Flow</span>
      </span>
    </span>
  );
}
