import { describe, expect, it } from 'vitest';
import { formatDuration, initials, titleCase } from './format';

/**
 * Durations are rendered on almost every screen, so the rounding boundaries are
 * worth pinning down — an incident that has been running for 59 minutes must
 * not read as "0h".
 */
describe('formatDuration', () => {
  it('shows seconds below a minute', () => {
    expect(formatDuration(0)).toBe('0s');
    expect(formatDuration(45)).toBe('45s');
  });

  it('switches to minutes at exactly one minute', () => {
    expect(formatDuration(59)).toBe('59s');
    expect(formatDuration(60)).toBe('1m');
    expect(formatDuration(3_599)).toBe('59m');
  });

  it('drops seconds once hours are involved', () => {
    expect(formatDuration(3_600)).toBe('1h');
    expect(formatDuration(5_400)).toBe('1h 30m');
    // 90 minutes and 59 seconds is still "1h 30m": nobody responding to an
    // hour-and-a-half outage cares about the seconds.
    expect(formatDuration(5_459)).toBe('1h 30m');
  });

  it('switches to days beyond 24 hours', () => {
    expect(formatDuration(86_400)).toBe('1d');
    expect(formatDuration(90_000)).toBe('1d 1h');
  });

  it('renders an em dash rather than a misleading zero for missing values', () => {
    // A null MTTR means "never resolved", which is emphatically not "0s".
    expect(formatDuration(null)).toBe('—');
    expect(formatDuration(undefined)).toBe('—');
    expect(formatDuration(-1)).toBe('—');
  });
});

describe('initials', () => {
  it('takes at most two initials', () => {
    expect(initials('Ada Lovelace')).toBe('AL');
    expect(initials('Mary Lee Berners Lee')).toBe('ML');
  });

  it('copes with a single name and with nothing at all', () => {
    expect(initials('Prince')).toBe('P');
    expect(initials(null)).toBe('??');
    expect(initials('')).toBe('??');
  });
});

describe('titleCase', () => {
  it('humanises the snake_case action names from the audit log', () => {
    expect(titleCase('incident.status_changed')).toBe('Incident Status Changed');
    expect(titleCase('member_removed')).toBe('Member Removed');
    expect(titleCase('postmortem.published')).toBe('Postmortem Published');
  });
});
