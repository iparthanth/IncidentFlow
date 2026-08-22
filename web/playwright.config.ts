import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end configuration.
 *
 * These tests run against the full docker-compose stack — real nginx, real
 * PostgreSQL, real Redis, real fan-out. That is the point: the interesting
 * failures in this system live in the seams between services (proxy buffering
 * killing SSE, a cookie dropped because SameSite was wrong, the realtime node
 * subscribing to a prefixed channel the publisher never writes to), and none of
 * them are reachable from a unit test.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',

  timeout: Number(process.env.E2E_TIMEOUT ?? 30_000),

  /**
   * These assertions wait on a real server, so the ceiling depends on what is
   * serving. Against the compose stack (nginx + php-fpm, 20 workers) 10s is
   * generous. Against `php artisan serve` it is not: that server handles one
   * request at a time, so a page issuing several concurrent queries has them
   * queue, and a detail view can take the better part of ten seconds to settle.
   *
   * Raise it with E2E_EXPECT_TIMEOUT when pointing at a development server.
   */
  expect: { timeout: Number(process.env.E2E_EXPECT_TIMEOUT ?? 10_000) },

  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
    // A trace on the first retry is enough to diagnose almost anything without
    // paying the recording cost on every green run.
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
