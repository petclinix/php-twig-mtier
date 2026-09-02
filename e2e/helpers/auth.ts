import { Page } from '@playwright/test';

/**
 * Nginx rate-limits /login and /register to 10 req/min with a burst of 5,
 * `nodelay` (docker/nginx/default.conf) — excess requests get a bare 429, not
 * an app response, and `nodelay` means excess requests are rejected
 * immediately rather than queued/delayed.
 *
 * Retrying reactively after a 429 doesn't converge here: recovering from one
 * means reloading the form (a 429 replaces the whole page, so the "Register"/
 * "Log in" button is gone) *and* resubmitting, i.e. 2 more requests — which
 * is double nginx's steady-state rate of 1 request per 6s, so naive
 * GET+wait+POST retries can never drain the bucket faster than they refill
 * it. Instead, acquireAuthZoneSlot below throttles proactively: it mirrors
 * nginx's own token bucket (a bit more conservatively) and sleeps just long
 * enough before each request that we're never the one sending the 429-th
 * one. A short reactive retry stays in place as a defensive fallback only.
 */
const AUTH_ZONE_MAX_TOKENS = 4; // nginx burst is 5; stay one under it
const AUTH_ZONE_REFILL_PER_MS = 9 / 60_000; // nginx rate is 10/min; refill a bit slower
const RATE_LIMIT_RETRY_DELAY_MS = 15_000;
const MAX_RATE_LIMIT_RETRIES = 5;

let authZoneTokens = AUTH_ZONE_MAX_TOKENS;
let authZoneLastRefill = Date.now();

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Blocks (if needed) until it's safe to send one more request to /login or
 * /register without exceeding nginx's rate limit, then reserves that slot.
 * All requests to either endpoint share one zone server-side, so this is
 * one process-wide bucket regardless of which Page issues the request —
 * correct here since the whole suite runs in a single worker (workers: 1).
 */
async function acquireAuthZoneSlot(): Promise<void> {
  const now = Date.now();
  authZoneTokens = Math.min(AUTH_ZONE_MAX_TOKENS, authZoneTokens + (now - authZoneLastRefill) * AUTH_ZONE_REFILL_PER_MS);
  authZoneLastRefill = now;

  if (authZoneTokens < 1) {
    const waitMs = (1 - authZoneTokens) / AUTH_ZONE_REFILL_PER_MS;
    await sleep(waitMs);
    authZoneLastRefill = Date.now();
    authZoneTokens = 1;
  }

  authZoneTokens -= 1;
}

export async function gotoRetrying(page: Page, url: string): Promise<void> {
  for (let attempt = 0; attempt < MAX_RATE_LIMIT_RETRIES; attempt++) {
    await acquireAuthZoneSlot();
    const response = await page.goto(url);
    if (response?.status() === 429) {
      await sleep(RATE_LIMIT_RETRY_DELAY_MS);
      continue;
    }
    return;
  }
  throw new Error(`Exceeded retries waiting out the auth rate limit on GET ${url}`);
}

/**
 * Navigates to `path` (skipped if `page` is already there — a failed
 * validation POST re-renders the same URL, so chained attempts on a shared
 * page don't need a fresh GET), then runs `fillAndSubmit` (fill the form +
 * click submit). Both requests go through acquireAuthZoneSlot, so a 429 here
 * would mean the proactive throttling above was somehow insufficient; the
 * fallback still redoes the navigation and the fill, since the 429 response
 * (served by nginx, never reaching the app) replaces the form that was on
 * the page.
 */
async function submitFormRetrying(page: Page, path: string, fillAndSubmit: () => Promise<void>): Promise<void> {
  let needsFreshLoad = new URL(page.url()).pathname !== path;

  for (let attempt = 0; attempt < MAX_RATE_LIMIT_RETRIES; attempt++) {
    if (needsFreshLoad) {
      await gotoRetrying(page, path);
      needsFreshLoad = false;
    }

    await acquireAuthZoneSlot();
    const responsePromise = page.waitForResponse(
      (response) => new URL(response.url()).pathname === path && response.request().method() === 'POST',
    );
    await fillAndSubmit();
    const response = await responsePromise;
    if (response.status() === 429) {
      await sleep(RATE_LIMIT_RETRY_DELAY_MS);
      needsFreshLoad = true;
      continue;
    }
    return;
  }
  throw new Error(`Exceeded retries waiting out the auth rate limit on POST ${path}`);
}

export type RegisterRole = 'owner' | 'vet';

export interface RegisterFields {
  email: string;
  password: string;
  /** Defaults to `password` — set explicitly to exercise the mismatch case. */
  passwordConfirmation?: string;
  role?: RegisterRole;
  firstName?: string;
  lastName?: string;
  /** Owners only. */
  phone?: string;
  /** Owners only. */
  address?: string;
  /** Vets only. */
  specialty?: string;
}

/**
 * Fills every field of the register form, one way or another (real value or
 * explicit blank) — assumes the form is already loaded. Never leaves a
 * field's fate to whatever was on the page before: tests in this suite
 * chain several attempts on one shared page to stay within the auth rate
 * limit (see submitFormRetrying), and a failed submission re-renders the
 * form with the previous attempt's values (Twig's `old.*`) still filled in —
 * so a field this call doesn't explicitly own could otherwise leak in from
 * an earlier attempt.
 *
 * The one exception is the role radios: once checked, a radio can only be
 * unchecked by checking a *different* one in its group, not cleared back to
 * neither — Playwright's `.uncheck()` refuses this (and so does a real
 * browser). A test that needs the "no role selected" state has to start
 * from a fresh page load instead of relying on this function to clear it.
 */
async function fillRegisterForm(page: Page, fields: RegisterFields): Promise<void> {
  await page.getByLabel('Email').fill(fields.email);
  await page.getByLabel('Password', { exact: true }).fill(fields.password);
  await page.getByLabel('Confirm password').fill(fields.passwordConfirmation ?? fields.password);

  if (fields.role) {
    await page.getByLabel(fields.role === 'owner' ? 'Owner' : 'Vet', { exact: true }).check();
  }

  await page.getByLabel('First name').fill(fields.firstName ?? '');
  await page.getByLabel('Last name').fill(fields.lastName ?? '');
  await page.getByLabel('Phone').fill(fields.phone ?? '');
  await page.getByLabel('Address').fill(fields.address ?? '');
  await page.getByLabel('Specialty').fill(fields.specialty ?? '');
}

/**
 * Fills and submits the register form, then returns without asserting an
 * outcome — for negative-path tests that expect validation errors and no
 * redirect. Callers assert on the resulting page themselves.
 */
export async function attemptRegister(page: Page, fields: RegisterFields): Promise<void> {
  await submitFormRetrying(page, '/register', async () => {
    await fillRegisterForm(page, fields);
    await page.getByRole('button', { name: 'Register' }).click();
  });
}

/**
 * Registers a new user via the register form, then waits for the post-
 * registration redirect to /dashboard (registration logs the user in
 * immediately — see AuthController::register()).
 */
export async function registerUser(
  page: Page,
  email: string,
  password: string,
  role: RegisterRole,
  overrides: Omit<RegisterFields, 'email' | 'password' | 'role'> = {},
): Promise<void> {
  await attemptRegister(page, {
    email,
    password,
    role,
    firstName: overrides.firstName ?? 'Test',
    lastName: overrides.lastName ?? 'User',
    phone: role === 'owner' ? (overrides.phone ?? '555-0100') : undefined,
    address: role === 'owner' ? (overrides.address ?? '1 Test Street') : undefined,
    specialty: role === 'vet' ? (overrides.specialty ?? 'General Practice') : undefined,
  });
  await page.waitForURL('/dashboard');
}

/**
 * Fills and submits the login form, then returns without asserting an
 * outcome — for negative-path tests (wrong password, deactivated account).
 */
export async function attemptLogin(page: Page, email: string, password: string): Promise<void> {
  await submitFormRetrying(page, '/login', async () => {
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
  });
}

/**
 * Fills the login form and waits for the post-login redirect to /dashboard.
 * Login is by email (not username) — see templates/auth/login.html.twig.
 */
export async function loginAs(page: Page, email: string, password: string): Promise<void> {
  await attemptLogin(page, email, password);
  await page.waitForURL('/dashboard');
}
