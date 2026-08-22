import { expect, test, type Page } from '@playwright/test';

/**
 * End-to-end, against the full docker-compose stack.
 *
 * These exist to cover what unit tests structurally cannot: the seams between
 * services. Every failure this file is designed to catch has the same shape —
 * each service works perfectly in isolation and the system does not:
 *
 *   - nginx buffering the SSE stream into silence
 *   - the refresh cookie dropped because SameSite or Secure was wrong
 *   - the realtime node subscribed to a prefixed channel the publisher never
 *     writes to
 *   - a proxy path rewrite that loses the ticket query string
 */

const DEMO_PASSWORD = 'incidentflow';

async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(DEMO_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Incidents' })).toBeVisible();
}

test.describe('incident lifecycle', () => {
  test('a responder signs in and sees the seeded incidents', async ({ page }) => {
    await signIn(page, 'responder@incidentflow.test');

    // The seeder leaves three incidents running on purpose.
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('row')).not.toHaveCount(1);
  });

  test('the session survives a reload', async ({ page }) => {
    await signIn(page, 'responder@incidentflow.test');
    await page.reload();

    // Proves the whole refresh path end to end: the HttpOnly cookie was set
    // with usable attributes, nginx passed it through, and the API accepted it.
    // The access token itself lives only in memory and does not survive.
    await expect(page.getByRole('heading', { name: 'Incidents' })).toBeVisible();
  });

  test('a reporter can open an incident and it appears on the list', async ({ page }) => {
    await signIn(page, 'reporter@incidentflow.test');

    const title = `Playwright smoke incident ${Date.now()}`;

    await page.getByRole('button', { name: 'Report incident' }).click();

    // Scoped to the dialog. Unscoped, `getByLabel('Severity')` also matches the
    // list's "Filter by severity" control, and `.last()` would quietly depend
    // on DOM order — the sort of locator that passes until someone reorders
    // the page.
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('What is happening?').fill(title);
    await dialog.getByLabel('Severity', { exact: true }).selectOption('sev3');
    await dialog.getByRole('button', { name: 'Report incident' }).click();

    // Redirected to the new incident, which means creation returned a real id.
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await expect(page.getByText(/INC-\d+/)).toBeVisible();

    // The timeline is written in the same transaction as the incident.
    await expect(page.getByText(/reported the incident/i)).toBeVisible();
  });

  test('a responder drives an incident through its lifecycle', async ({ page }) => {
    await signIn(page, 'responder@incidentflow.test');

    await page.getByRole('link', { name: /INC-\d+/ }).first().click();
    await expect(page.getByText(/INC-\d+/).first()).toBeVisible();

    const acknowledge = page.getByRole('button', { name: /Mark acknowledged/i });

    if (await acknowledge.isVisible()) {
      await acknowledge.click();
      await expect(page.getByText(/acknowledged the incident/i)).toBeVisible();
    }

    // The buttons come from the server's `allowed_transitions`, so whatever is
    // on screen is guaranteed to be a legal move — there is no "closed" button
    // on an open incident to accidentally click.
    await expect(page.getByRole('button', { name: /Mark open/i })).toHaveCount(0);
  });

  test('a viewer cannot report an incident', async ({ page }) => {
    await signIn(page, 'viewer@incidentflow.test');

    // The control is hidden because the permission is absent — and the API
    // would refuse it regardless, which is the check that actually matters.
    await expect(page.getByRole('button', { name: 'Report incident' })).toHaveCount(0);
  });

  test('the realtime stream connects through nginx', async ({ page }) => {
    await signIn(page, 'responder@incidentflow.test');

    // The single most valuable assertion in this file. It only passes if the
    // ticket endpoint minted a token, nginx proxied /realtime without
    // buffering, the node verified the signature with the public key, and the
    // channel prefixes on both sides matched.
    await expect(page.getByRole('status').filter({ hasText: 'Live' })).toBeVisible({
      timeout: 15_000,
    });
  });

  test('metrics render from real seeded history', async ({ page }) => {
    await signIn(page, 'commander@incidentflow.test');
    await page.getByRole('link', { name: 'Metrics' }).click();

    await expect(page.getByRole('heading', { name: 'Reliability metrics' })).toBeVisible();
    await expect(page.getByText('Mean time to acknowledge')).toBeVisible();

    // Percentiles are shown next to every average, deliberately.
    await expect(page.getByText(/p50/).first()).toBeVisible();
  });
});
