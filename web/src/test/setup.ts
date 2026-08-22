import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

/**
 * jsdom implements neither `EventSource` nor `crypto.randomUUID`, and the app
 * touches both on almost every path — the stream hook opens a connection and
 * the API client stamps every request with an id. Stubbing them here keeps
 * every test from having to remember.
 */
class MockEventSource {
  static instances: MockEventSource[] = [];

  readonly listeners = new Map<string, Set<(event: MessageEvent) => void>>();
  onmessage: ((event: MessageEvent) => void) | null = null;
  onerror: (() => void) | null = null;
  readyState = 0;

  constructor(readonly url: string) {
    MockEventSource.instances.push(this);
  }

  addEventListener(type: string, listener: (event: MessageEvent) => void): void {
    const bucket = this.listeners.get(type) ?? new Set();
    bucket.add(listener);
    this.listeners.set(type, bucket);
  }

  removeEventListener(type: string, listener: (event: MessageEvent) => void): void {
    this.listeners.get(type)?.delete(listener);
  }

  close(): void {
    this.readyState = 2;
  }

  /** Test helper: push an event as though the server had sent it. */
  emit(type: string, data: unknown, lastEventId = ''): void {
    const event = new MessageEvent(type, { data: JSON.stringify(data), lastEventId });
    this.listeners.get(type)?.forEach((listener) => listener(event));
    if (type === 'message') this.onmessage?.(event);
  }
}

vi.stubGlobal('EventSource', MockEventSource);

if (!globalThis.crypto?.randomUUID) {
  Object.defineProperty(globalThis, 'crypto', {
    value: {
      ...globalThis.crypto,
      randomUUID: () => '00000000-0000-4000-8000-000000000000',
    },
    configurable: true,
  });
}

export { MockEventSource };
