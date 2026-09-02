import { test, expect, type Page } from '@playwright/test';
import { attemptLogin, loginAs, registerUser } from '../helpers/auth';

/**
 * Admin feature tests: stats dashboard, user management, activity log.
 * The admin account is seeded via db/seed.sql (never self-registered):
 * admin@petclinix.local / admin12345.
 *
 * The admin session is logged in once in beforeAll and reused for every
 * test — see the rate-limit note in helpers/auth.ts. Target users are
 * registered on short-lived, separate pages so they don't disturb the
 * admin session on `adminPage`.
 */

test.describe.configure({ mode: 'serial' });

const ADMIN_EMAIL = 'admin@petclinix.local';
const ADMIN_PASSWORD = 'admin12345';

const ts = Date.now();
const password = 'testpass123';
const targetOwnerEmail = `admin_target_owner_${ts}@e2e.test`;
const targetVetEmail = `admin_target_vet_${ts}@e2e.test`;

let adminPage: Page;

test.beforeAll(async ({ browser }) => {
  const setupPage = await browser.newPage();
  await registerUser(setupPage, targetOwnerEmail, password, 'owner');
  await registerUser(setupPage, targetVetEmail, password, 'vet');
  await setupPage.close();

  adminPage = await browser.newPage();
  await loginAs(adminPage, ADMIN_EMAIL, ADMIN_PASSWORD);
});

test.afterAll(async () => {
  await adminPage.close();
});

test('admin dashboard nav shows Stats, Manage Users, and Activity Log', async () => {
  await adminPage.goto('/dashboard');

  await expect(adminPage.getByRole('link', { name: 'Stats' })).toBeVisible();
  await expect(adminPage.getByRole('link', { name: 'Manage Users' })).toBeVisible();
  await expect(adminPage.getByRole('link', { name: 'Activity Log' })).toBeVisible();
  await expect(adminPage.getByRole('button', { name: 'Log out' })).toBeVisible();
});

test('admin can view the stats page with owner/vet/pet/appointment counts', async () => {
  await adminPage.goto('/admin');

  await expect(adminPage.getByRole('heading', { name: 'Stats' })).toBeVisible();
  await expect(adminPage.getByText(/^Owners: \d+/)).toBeVisible();
  await expect(adminPage.getByText(/^Vets: \d+/)).toBeVisible();
  await expect(adminPage.getByText(/^Pets: \d+/)).toBeVisible();
  await expect(adminPage.getByText(/^Appointments: \d+/)).toBeVisible();
  await expect(adminPage.getByRole('heading', { name: 'Appointments by Status' })).toBeVisible();
});

test('admin can view all users, including newly registered ones', async () => {
  await adminPage.goto('/admin/users');

  await expect(adminPage.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(adminPage.getByRole('columnheader', { name: 'Email' })).toBeVisible();
  await expect(adminPage.getByRole('columnheader', { name: 'Role' })).toBeVisible();
  await expect(adminPage.getByRole('columnheader', { name: 'Status' })).toBeVisible();

  const ownerRow = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: targetOwnerEmail, exact: true }),
  });
  await expect(ownerRow).toContainText('owner');
  await expect(ownerRow).toContainText('active');

  const vetRow = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: targetVetEmail, exact: true }),
  });
  await expect(vetRow).toContainText('vet');
});

test('admin can deactivate and reactivate a user', async ({ browser }) => {
  const toggleEmail = `admin_toggle_${ts}@e2e.test`;
  const setupPage = await browser.newPage();
  await registerUser(setupPage, toggleEmail, password, 'owner');
  await setupPage.close();

  await adminPage.goto('/admin/users');
  const row = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: toggleEmail, exact: true }),
  });
  await expect(row).toContainText('active');

  await row.getByRole('button', { name: 'Deactivate' }).click();
  const deactivatedRow = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: toggleEmail, exact: true }),
  });
  await expect(deactivatedRow).toContainText('deactivated');
  await expect(deactivatedRow.getByRole('button', { name: 'Activate' })).toBeVisible();

  await deactivatedRow.getByRole('button', { name: 'Activate' }).click();
  const reactivatedRow = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: toggleEmail, exact: true }),
  });
  await expect(reactivatedRow).toContainText('active');
  await expect(reactivatedRow).not.toContainText('deactivated');
});

test('a deactivated user can no longer log in', async ({ browser }) => {
  const lockedEmail = `admin_locked_${ts}@e2e.test`;
  const setupPage = await browser.newPage();
  await registerUser(setupPage, lockedEmail, password, 'owner');

  await adminPage.goto('/admin/users');
  const row = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: lockedEmail, exact: true }),
  });
  await row.getByRole('button', { name: 'Deactivate' }).click();

  await attemptLogin(setupPage, lockedEmail, password);

  await expect(setupPage.getByText('This account has been deactivated.')).toBeVisible();
  await setupPage.close();
});

test("admin cannot deactivate their own account", async () => {
  await adminPage.goto('/admin/users');
  const adminRow = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: ADMIN_EMAIL, exact: true }),
  });
  await expect(adminRow).toContainText('active');

  // The template doesn't hide the button for the admin's own row — the
  // no-op is enforced server-side (AdminService::setUserActive is only
  // called when actorUserId !== targetUserId) — so clicking it should
  // silently leave the admin's own account active.
  await adminRow.getByRole('button', { name: 'Deactivate' }).click();

  const adminRowAfter = adminPage.getByRole('row').filter({
    has: adminPage.getByRole('cell', { name: ADMIN_EMAIL, exact: true }),
  });
  await expect(adminRowAfter).toContainText('active');
  await expect(adminRowAfter).not.toContainText('deactivated');
});

test('activity log records registration and login events', async ({ browser }) => {
  const activityEmail = `admin_activity_${ts}@e2e.test`;
  const setupPage = await browser.newPage();
  await registerUser(setupPage, activityEmail, password, 'owner');
  await loginAs(setupPage, activityEmail, password);
  await setupPage.close();

  await adminPage.goto('/admin/activity');

  await expect(adminPage.getByRole('heading', { name: 'Activity Log' })).toBeVisible();
  const entries = adminPage.getByRole('listitem').filter({ hasText: activityEmail });
  await expect(entries.filter({ hasText: 'user_registered' })).toBeVisible();
  await expect(entries.filter({ hasText: 'user_login' })).toBeVisible();
});
