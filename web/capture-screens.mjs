/**
 * Captures every screen of IncidentFlow as a PNG for the teaching manual.
 *
 * Run from web/:  node capture-screens.mjs
 *
 * Not a test. It asserts nothing and fails loudly instead: a manual built from
 * screenshots that silently captured a blank page or an error state is worse
 * than no manual, because the reader trusts the picture over the prose.
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8080';
const OUT = resolve('../docs/img');
const PASSWORD = 'incidentflow';

const ACCOUNTS = {
  commander: 'commander@incidentflow.test', // Daniel Okoye  — full incident powers
  viewer: 'viewer@incidentflow.test',       // Sofia Rossi   — read-only
  admin: 'admin@incidentflow.test',         // Riya Sharma   — org administration
};

mkdirSync(OUT, { recursive: true });
let shotCount = 0;

async function shoot(page, name, { full = false, expect = null } = {}) {
  /*
   * `expect` is the heading that proves we are on the intended screen, and it
   * is not optional decoration. The first run of this script produced four
   * files that were byte-identical to the login page: a full page.goto()
   * reload drops the in-memory access token, the app renders Sign in while
   * /auth/refresh is in flight, and a screenshot taken on a timer caught that
   * intermediate frame. Nothing errored. The manual would simply have shown a
   * login form captioned "the metrics page", and the reader would believe it.
   */
  if (expect) {
    await page
      .getByRole('heading', { name: expect })
      .first()
      .waitFor({ state: 'visible', timeout: 20000 })
      .catch(() => {
        throw new Error(
          `REFUSING to save ${name}.png — expected heading "${expect}" never appeared. ` +
            `The page is probably showing Sign in instead.`,
        );
      });
  }
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(400);
  const file = `${OUT}/${name}.png`;
  await page.screenshot({ path: file, fullPage: full });
  shotCount += 1;
  console.log(`  [${String(shotCount).padStart(2, '0')}] ${name}.png  (verified: ${expect ?? 'no assertion'})`);
}

async function signIn(page, email) {
  await page.goto(`${BASE}/login`);
  await page.getByLabel('Work email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('heading', { name: 'Incidents' }).waitFor({ timeout: 15000 });
}

const browser = await chromium.launch();
const ctx = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  // Retina-density output, so code and labels stay readable when LaTeX scales
  // the image down to page width.
  deviceScaleFactor: 2,
});
const page = await ctx.newPage();

console.log(`Capturing from ${BASE} -> ${OUT}`);

// ---- 1. Unauthenticated surface -------------------------------------------
await page.goto(`${BASE}/login`);
await shoot(page, '01-login-empty', { expect: 'Sign in' });

/*
 * Deliberately a DIFFERENT identity from the one used for the tour below.
 * `throttle:auth` counts login, register and refresh against the same
 * per-identity bucket, so spending a failure on commander@ here left it one
 * request short later and the run logged itself out mid-tour.
 */
await page.getByLabel('Work email').fill('reporter@incidentflow.test');
await page.getByLabel('Password', { exact: true }).fill('the-wrong-password');
await page.getByRole('button', { name: 'Sign in' }).click();
await page.waitForTimeout(1200);
await shoot(page, '02-login-error', { expect: 'Sign in' });

await page.goto(`${BASE}/login`);
await page.getByLabel('Password', { exact: true }).fill('incidentflow');
await page.getByRole('button', { name: 'Show password' }).click();
await shoot(page, '03-login-password-revealed', { expect: 'Sign in' });

await page.goto(`${BASE}/register`);
await shoot(page, '04-register', { expect: 'Create your organization' });

// ---- 2. The commander's view ----------------------------------------------
await signIn(page, ACCOUNTS.commander);
await shoot(page, '05-incidents-list', { expect: 'Incidents' });
await shoot(page, '05b-incidents-list-full', { full: true, expect: 'Incidents' });

// Open the first incident in the list.
// The row is not clickable; the incident reference in the first cell is a Link.
await page.locator('table tbody tr a[href^="/incidents/"]').first().click();
await page.waitForURL(/\/incidents\/\d+/, { timeout: 15000 });
await page.getByText(/timeline/i).first().waitFor({ timeout: 15000 });
await shoot(page, '06-incident-detail');
await shoot(page, '06b-incident-detail-full', { full: true });

/*
 * Click the sidebar rather than calling page.goto(). goto() is a full browser
 * reload: the in-memory access token is lost and the app must spend an
 * /auth/refresh to get another one. Doing that once per screen exhausted the
 * per-identity auth limit partway through the tour, and every screen after it
 * captured the login page instead. Clicking is also simply what a user does.
 */
for (const [name, link, heading] of [
  ['07-services', 'Services', 'Services'],
  ['08-metrics', 'Metrics', 'Reliability metrics'],
  ['09-postmortems', 'Postmortems', 'Postmortems'],
]) {
  await page.getByRole('link', { name: link, exact: true }).first().click();
  await shoot(page, name, { expect: heading });
  await shoot(page, `${name}-full`, { full: true, expect: heading });
}

await page.goto(`${BASE}/this-route-does-not-exist`);
await shoot(page, '10-not-found', { expect: 'Page not found' });

// ---- 3. Role differences (the permission model, made visible) -------------
await page.goto(`${BASE}/incidents`);
await page.getByRole('button', { name: /sign out|log out/i }).first().click().catch(async () => {
  await ctx.clearCookies();
  await page.goto(`${BASE}/login`);
});
await page.waitForTimeout(900);

await signIn(page, ACCOUNTS.viewer);
await shoot(page, '11-incidents-as-viewer', { expect: 'Incidents' });

await ctx.clearCookies();
await signIn(page, ACCOUNTS.admin);
await shoot(page, '12-incidents-as-admin', { expect: 'Incidents' });
await page.getByRole('link', { name: 'Admin', exact: true }).first().click();
await shoot(page, '13-admin', { expect: 'Administration' });
await shoot(page, '13b-admin-full', { full: true, expect: 'Administration' });

// ---- 4. The operator surfaces ---------------------------------------------
await page.goto(`${BASE}/horizon`);
await page.waitForTimeout(2000);
await shoot(page, '14-horizon-dashboard');

await page.goto('http://localhost:8025');
await page.waitForTimeout(1500);
await shoot(page, '15-mailpit');

await browser.close();
console.log(`\nDone. ${shotCount} screenshots written to docs/img/`);
