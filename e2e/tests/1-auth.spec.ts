import { test, expect, type Page } from '@playwright/test';
import { attemptLogin, attemptRegister, loginAs, registerUser } from '../helpers/auth';

/**
 * Authentication tests: registration and login, for both roles.
 * Covers: register/login (owner + vet), server-side validation errors,
 * dashboard content per role, logout, and the auth guard on protected pages.
 *
 * Each describe block runs serially against one shared page instead of a
 * fresh one per test: /login and /register share an nginx rate-limit zone
 * (docker/nginx/default.conf, burst 5, 10 req/min sustained), and a failed
 * validation POST re-renders the same form — so chaining attempts on one
 * page means each subsequent attempt only costs a POST, not a GET+POST.
 * helpers/auth.ts retries through any 429 that still occurs, but avoiding
 * the hits in the first place keeps the suite fast.
 */

const ts = Date.now();
const password = 'testpass123';

test.describe('Registration', () => {
  test.describe.configure({ mode: 'serial' });

  let page: Page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
  });

  test.afterAll(async () => {
    await page.close();
  });

  test('registration fails when no role is chosen', async () => {
    // Must run before any test that picks a role: once a radio in the
    // group is checked, it can only be replaced by checking the *other*
    // one, never cleared back to neither (see fillRegisterForm's doc
    // comment) — so this is the one test in the chain that needs a truly
    // untouched form, which only the first attempt on a fresh page has.
    await attemptRegister(page, {
      email: `auth_norole_${ts}@e2e.test`,
      password,
      firstName: 'Test',
      lastName: 'User',
    });

    await expect(page).toHaveURL('/register');
    await expect(page.getByText('Choose a role.')).toBeVisible();
  });

  test('owner can register and lands on the dashboard', async () => {
    await registerUser(page, `auth_owner_${ts}@e2e.test`, password, 'owner');

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByText(`auth_owner_${ts}@e2e.test`)).toBeVisible();
    await expect(page.getByText('(owner)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'My Pets' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Appointments' })).toBeVisible();
  });

  test('vet can register and lands on the dashboard', async () => {
    // Registering again while already logged in as the owner above still
    // works — /register carries no auth guard — and moves the session over
    // to the new vet account.
    await registerUser(page, `auth_vet_${ts}@e2e.test`, password, 'vet');

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByText('(vet)')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Availability' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Appointments' })).toBeVisible();
  });

  test('registration fails when passwords do not match', async () => {
    await attemptRegister(page, {
      email: `auth_mismatch_${ts}@e2e.test`,
      password: 'testpass123',
      passwordConfirmation: 'somethingelse',
      role: 'owner',
      firstName: 'Test',
      lastName: 'User',
      phone: '555-0100',
      address: '1 Test Street',
    });

    await expect(page).toHaveURL('/register');
    await expect(page.getByText('Passwords do not match.')).toBeVisible();
  });

  test('registration fails when password is too short', async () => {
    await attemptRegister(page, {
      email: `auth_shortpw_${ts}@e2e.test`,
      password: 'ab',
      role: 'owner',
      firstName: 'Test',
      lastName: 'User',
      phone: '555-0100',
      address: '1 Test Street',
    });

    await expect(page).toHaveURL('/register');
    await expect(page.getByText('Password must be at least 8 characters.')).toBeVisible();
  });

  test('registration fails when an owner omits phone and address', async () => {
    await attemptRegister(page, {
      email: `auth_ownernophone_${ts}@e2e.test`,
      password,
      role: 'owner',
      firstName: 'Test',
      lastName: 'User',
    });

    await expect(page).toHaveURL('/register');
    await expect(page.getByText('Phone and address are required for owners.')).toBeVisible();
  });

  test('registration fails when a vet omits specialty', async () => {
    await attemptRegister(page, {
      email: `auth_vetnospec_${ts}@e2e.test`,
      password,
      role: 'vet',
      firstName: 'Test',
      lastName: 'User',
    });

    await expect(page).toHaveURL('/register');
    await expect(page.getByText('Specialty is required for vets.')).toBeVisible();
  });

  test('registration fails for an email that is already registered', async () => {
    // Reuses the owner email registered in the first test of this file.
    await attemptRegister(page, {
      email: `auth_owner_${ts}@e2e.test`,
      password,
      role: 'owner',
      firstName: 'Test',
      lastName: 'User',
      phone: '555-0100',
      address: '1 Test Street',
    });

    await expect(page).toHaveURL('/register');
    await expect(page.getByText('An account with that email already exists.')).toBeVisible();
  });
});

test.describe('Login', () => {
  test.describe.configure({ mode: 'serial' });

  const loginEmail = `auth_login_owner_${ts}@e2e.test`;

  let page: Page;

  test.beforeAll(async ({ browser }) => {
    const setupPage = await browser.newPage();
    await registerUser(setupPage, loginEmail, password, 'owner');
    await setupPage.close();

    page = await browser.newPage();
  });

  test.afterAll(async () => {
    await page.close();
  });

  test('login fails with wrong password', async () => {
    await attemptLogin(page, loginEmail, 'wrongpassword');

    await expect(page).toHaveURL('/login');
    await expect(page.getByText('Invalid email or password.')).toBeVisible();
  });

  test('owner can log in and reach the dashboard', async () => {
    // Reuses the still-loaded /login form from the failed attempt above.
    await loginAs(page, loginEmail, password);

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByText(loginEmail)).toBeVisible();
  });

  test('logging out returns to the public nav', async () => {
    // Already logged in from the previous test — no need to log in again.
    await page.getByRole('button', { name: 'Log out' }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Register' })).toBeVisible();
  });

  test('unauthenticated user is redirected to login when visiting a protected page', async () => {
    await page.goto('/dashboard');

    await expect(page).toHaveURL('/login');
  });
});
