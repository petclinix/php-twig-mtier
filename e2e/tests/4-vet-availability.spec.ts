import { test, expect, type Page } from '@playwright/test';
import { registerUser } from '../helpers/auth';

/**
 * Vet weekly availability and exception management.
 *
 * One vet is registered once in beforeAll and reused for every test in this
 * file (registration logs the vet in and redirects to /dashboard) — see the
 * rate-limit note in helpers/auth.ts.
 */

test.describe.configure({ mode: 'serial' });

const ts = Date.now();
const vetEmail = `avail_vet_${ts}@e2e.test`;
const password = 'testpass123';

let page: Page;

test.beforeAll(async ({ browser }) => {
  page = await browser.newPage();
  await registerUser(page, vetEmail, password, 'vet');
});

test.beforeEach(async () => {
  await page.goto('/vet/availability');
});

test.afterAll(async () => {
  await page.close();
});

test('availability page renders with empty state', async () => {
  await expect(page.getByRole('heading', { name: 'My Weekly Availability' })).toBeVisible();
  await expect(page.getByText('No recurring availability set yet.')).toBeVisible();
  await expect(page.getByText('No exceptions set.')).toBeVisible();
});

test('vet can add a weekly availability window', async () => {
  await page.getByLabel('Day of week').selectOption('monday');
  await page.getByLabel('Starts at', { exact: true }).fill('09:00');
  await page.getByLabel('Ends at', { exact: true }).fill('17:00');
  await page.getByRole('button', { name: 'Add', exact: true }).click();

  await expect(page.getByText('Monday: 09:00 – 17:00')).toBeVisible();
});

test('adding a window with an end time before the start time shows a validation error', async () => {
  await page.getByLabel('Day of week').selectOption('tuesday');
  await page.getByLabel('Starts at', { exact: true }).fill('17:00');
  await page.getByLabel('Ends at', { exact: true }).fill('09:00');
  await page.getByRole('button', { name: 'Add', exact: true }).click();

  await expect(page.getByText('End time must be after the start time.')).toBeVisible();
  await expect(page.getByText('Tuesday:')).not.toBeVisible();
});

test('vet can remove a weekly availability window', async () => {
  await page.getByLabel('Day of week').selectOption('wednesday');
  await page.getByLabel('Starts at', { exact: true }).fill('08:00');
  await page.getByLabel('Ends at', { exact: true }).fill('12:00');
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  const row = page.getByRole('listitem').filter({ hasText: 'Wednesday: 08:00 – 12:00' });
  await expect(row).toBeVisible();

  await row.getByRole('button', { name: 'Remove' }).click();

  await expect(page.getByText('Wednesday: 08:00 – 12:00')).not.toBeVisible();
});

test('vet can add a fully-unavailable exception', async () => {
  await page.getByLabel('Date').fill('2030-01-01');
  await page.getByRole('button', { name: 'Add Exception' }).click();

  const row = page.getByRole('listitem').filter({ hasText: '2030-01-01' });
  await expect(row).toContainText('Unavailable (vacation/sick leave)');
});

test('vet can add a custom-hours exception', async () => {
  await page.getByLabel('Date').fill('2030-02-14');
  await page.getByLabel('Custom hours instead of fully unavailable').check();
  await page.getByLabel('Starts at (if custom hours)').fill('10:00');
  await page.getByLabel('Ends at (if custom hours)').fill('14:00');
  await page.getByRole('button', { name: 'Add Exception' }).click();

  const row = page.getByRole('listitem').filter({ hasText: '2030-02-14' });
  await expect(row).toContainText('Custom hours 10:00 – 14:00');
});

test('a custom-hours exception without times shows validation errors', async () => {
  await page.getByLabel('Date').fill('2030-03-01');
  await page.getByLabel('Custom hours instead of fully unavailable').check();
  await page.getByRole('button', { name: 'Add Exception' }).click();

  await expect(page.getByText('Choose a start time.')).toBeVisible();
  await expect(page.getByText('Choose an end time.')).toBeVisible();
  await expect(page.getByRole('listitem').filter({ hasText: '2030-03-01' })).not.toBeVisible();
});

test('vet can remove an exception', async () => {
  await page.getByLabel('Date').fill('2030-04-01');
  await page.getByRole('button', { name: 'Add Exception' }).click();
  const row = page.getByRole('listitem').filter({ hasText: '2030-04-01' });
  await expect(row).toBeVisible();

  await row.getByRole('button', { name: 'Remove' }).click();

  await expect(page.getByText('2030-04-01')).not.toBeVisible();
});
