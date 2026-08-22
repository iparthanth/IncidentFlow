import { formatDistanceToNowStrict, format, parseISO } from 'date-fns';

/**
 * Presentation helpers.
 *
 * The duration formatter deserves a note: an incident-management UI shows
 * durations constantly, and "5400s" is unreadable while "1h 30m" is not.
 * Precision is deliberately shed as the magnitude grows — nobody responding to
 * a four-hour outage cares about the seconds.
 */

export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 0) return '—';
  if (seconds < 60) return `${Math.round(seconds)}s`;

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m`;

  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;
  if (hours < 24) {
    return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
  }

  const days = Math.floor(hours / 24);
  const remainingHours = hours % 24;
  return remainingHours > 0 ? `${days}d ${remainingHours}h` : `${days}d`;
}

export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return `${formatDistanceToNowStrict(parseISO(iso))} ago`;
  } catch {
    return '—';
  }
}

export function formatAbsolute(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return format(parseISO(iso), 'd MMM yyyy, HH:mm');
  } catch {
    return '—';
  }
}

/** Both, because a timeline needs "2h ago" and a postmortem needs the timestamp. */
export function formatTimestamp(iso: string | null | undefined): { relative: string; absolute: string } {
  return { relative: formatRelative(iso), absolute: formatAbsolute(iso) };
}

export function formatPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return `${value.toFixed(1)}%`;
}

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat().format(value);
}

/** Two initials, for the avatar placeholder. */
export function initials(name: string | null | undefined): string {
  if (!name) return '??';
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}

/**
 * Humanises the machine-readable identifiers the API returns.
 *
 * Dots are separators too, not sentence punctuation: audit actions arrive as
 * `incident.status_changed`, and "Incident.Status Changed" reads like a bug
 * where "Incident Status Changed" reads like a sentence.
 */
export function titleCase(value: string): string {
  return value
    .replace(/[._-]+/g, ' ')
    .trim()
    .replace(/\b\w/g, (character) => character.toUpperCase());
}
