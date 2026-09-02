import { test, expect, type Page } from '@playwright/test';
import { registerUser } from '../helpers/auth';

/**
 * Owner pet management: add a pet, list pets, view a pet's profile page.
 *
 * All tests share one logged-in page (registration logs the owner in and
 * redirects to /dashboard) instead of logging in per test — see the rate
 * limit note in helpers/auth.ts.
 */

test.describe.configure({ mode: 'serial' });

const ts = Date.now();
const ownerEmail = `pet_owner_${ts}@e2e.test`;
const password = 'testpass123';

// Smallest valid PNG (1x1 transparent pixel), used to exercise photo upload
// without needing a fixture file on disk.
const ONE_PX_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64',
);

let page: Page;

test.beforeAll(async ({ browser }) => {
  page = await browser.newPage();
  await registerUser(page, ownerEmail, password, 'owner');
});

test.beforeEach(async () => {
  await page.goto('/owner/pets');
});

test.afterAll(async () => {
  await page.close();
});

test('pets page renders with add-pet form and empty state', async () => {
  await expect(page.getByRole('heading', { name: 'My Pets' })).toBeVisible();
  await expect(page.getByText("You haven't added any pets yet.")).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Add a Pet' })).toBeVisible();
});

test('owner can add a pet with only the required fields', async () => {
  await page.getByLabel('Name').fill('Fluffy');
  await page.getByLabel('Species').fill('Cat');
  await page.getByRole('button', { name: 'Add Pet' }).click();

  await expect(page.getByRole('link', { name: 'Fluffy' })).toBeVisible();
  const petRow = page.getByRole('listitem').filter({ hasText: 'Fluffy' });
  await expect(petRow).toContainText('Cat');
});

test('owner can add a pet with breed and birth date', async () => {
  await page.getByLabel('Name').fill('Rex');
  await page.getByLabel('Species').fill('Dog');
  await page.getByLabel('Breed').fill('Labrador');
  await page.getByLabel('Birth date').fill('2020-06-15');
  await page.getByRole('button', { name: 'Add Pet' }).click();

  const petRow = page.getByRole('listitem').filter({ hasText: 'Rex' });
  await expect(petRow).toContainText('Dog');
  await expect(petRow).toContainText('Labrador');
  await expect(petRow).toContainText('2020-06-15');
});

test('owner can add a pet with a photo and see its thumbnail', async () => {
  await page.getByLabel('Name').fill('Pixel');
  await page.getByLabel('Species').fill('Cat');
  await page.getByLabel('Photo').setInputFiles({
    name: 'pixel.png',
    mimeType: 'image/png',
    buffer: ONE_PX_PNG,
  });
  await page.getByRole('button', { name: 'Add Pet' }).click();

  const petRow = page.getByRole('listitem').filter({ hasText: 'Pixel' });
  const thumbnail = petRow.locator('img');
  await expect(thumbnail).toBeVisible();
  await expect(thumbnail).toHaveAttribute('src', /^\/uploads\/pets\//);
});

test('form resets after successfully adding a pet', async () => {
  await page.getByLabel('Name').fill('Birdie');
  await page.getByLabel('Species').fill('Bird');
  await page.getByRole('button', { name: 'Add Pet' }).click();

  await expect(page.getByRole('link', { name: 'Birdie' })).toBeVisible();
  await expect(page.getByLabel('Name')).toHaveValue('');
});

test('pet name is required to submit the add-pet form', async () => {
  await page.getByLabel('Species').fill('Dog');
  await page.getByRole('button', { name: 'Add Pet' }).click();

  // Native "required" validation blocks submission and focuses the empty field.
  await expect(page.getByLabel('Name')).toBeFocused();
  await expect(page).toHaveURL('/owner/pets');
});

test('clicking a pet opens its profile with an empty visit history', async () => {
  await page.getByLabel('Name').fill('Nemo');
  await page.getByLabel('Species').fill('Fish');
  await page.getByRole('button', { name: 'Add Pet' }).click();

  await page.getByRole('link', { name: 'Nemo' }).click();

  await expect(page).toHaveURL(/\/owner\/pets\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Nemo' })).toBeVisible();
  await expect(page.getByText('No recorded visits yet.')).toBeVisible();
});

test('pet profile has a link back to My Pets', async () => {
  const petRow = page.getByRole('listitem').filter({ hasText: 'Fluffy' });
  await petRow.getByRole('link', { name: 'Fluffy' }).click();

  await page.getByRole('link', { name: /Back to My Pets/ }).click();

  await expect(page).toHaveURL('/owner/pets');
});
