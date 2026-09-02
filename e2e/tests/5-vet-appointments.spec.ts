import { test, expect, type Page } from '@playwright/test';
import { registerUser } from '../helpers/auth';
import { ensureVetIsAlwaysOpen } from '../helpers/availability';
import { appointmentRow, bookAppointment } from '../helpers/appointments';

/**
 * Vet appointment management (confirm/cancel/no-show) and visit
 * documentation, plus the owner-side view of a recorded visit.
 *
 * One vet and one owner (with one pet) are registered once in beforeAll and
 * reused for every test — see the rate-limit note in helpers/auth.ts. Each
 * test books its own appointment (unique `reason`) rather than sharing one,
 * so the confirm/no-show/cancel/visit transitions (each irreversible) don't
 * interfere with each other.
 */

test.describe.configure({ mode: 'serial' });

const ts = Date.now();
const password = 'testpass123';
const vetEmail = `vis_vet_${ts}@e2e.test`;
const ownerEmail = `vis_owner_${ts}@e2e.test`;
const vetLastName = `VisVet${ts}`;
const vetLabel = `Dr. E2E ${vetLastName} (General Practice)`;
const petName = `VisPet_${ts}`;

let vetPage: Page;
let ownerPage: Page;

test.beforeAll(async ({ browser }) => {
  vetPage = await browser.newPage();
  await registerUser(vetPage, vetEmail, password, 'vet', {
    firstName: 'E2E',
    lastName: vetLastName,
    specialty: 'General Practice',
  });
  await ensureVetIsAlwaysOpen(vetPage);

  ownerPage = await browser.newPage();
  await registerUser(ownerPage, ownerEmail, password, 'owner');
  await ownerPage.goto('/owner/pets');
  await ownerPage.getByLabel('Name').fill(petName);
  await ownerPage.getByLabel('Species').fill('Cat');
  await ownerPage.getByRole('button', { name: 'Add Pet' }).click();
  await expect(ownerPage.getByRole('link', { name: petName })).toBeVisible();
});

test.afterAll(async () => {
  await vetPage.close();
  await ownerPage.close();
});

test('vet appointment list shows a freshly booked appointment as requested', async () => {
  const reason = `list-${ts}`;
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason });

  await vetPage.goto('/vet/appointments');
  const row = appointmentRow(vetPage, reason);

  await expect(vetPage.getByRole('heading', { name: 'My Appointments' })).toBeVisible();
  await expect(row).toContainText(petName);
  await expect(row).toContainText('(requested)');
  await expect(row.getByRole('button', { name: 'Confirm' })).toBeVisible();
  await expect(row.getByRole('button', { name: 'Cancel' })).toBeVisible();
  await expect(row.getByRole('link', { name: 'Record Visit' })).not.toBeVisible();
});

test('vet can confirm a booked appointment', async () => {
  const reason = `confirm-${ts}`;
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason });

  await vetPage.goto('/vet/appointments');
  const row = appointmentRow(vetPage, reason);
  await row.getByRole('button', { name: 'Confirm' }).click();

  await expect(row).toContainText('(confirmed)');
  await expect(row.getByRole('button', { name: 'Confirm' })).not.toBeVisible();
  await expect(row.getByRole('link', { name: 'Record Visit' })).toBeVisible();
  await expect(row.getByRole('button', { name: 'Mark No-Show' })).toBeVisible();
  await expect(row.getByRole('button', { name: 'Cancel' })).toBeVisible();
});

test('marking no-show before the scheduled time has passed has no effect', async () => {
  // AppointmentTransitionService::hasScheduledTimePassed() only allows the
  // NoShow transition once `scheduledAt` is in the past. Every slot the
  // booking UI can offer is in the future (AppointmentAvailabilityService
  // only returns upcoming slots), so this is the one outcome reachable
  // through the UI — the controller ignores the transition's success flag
  // and redirects either way, so this documents real, current behavior.
  const reason = `noshow-${ts}`;
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason });

  await vetPage.goto('/vet/appointments');
  const row = appointmentRow(vetPage, reason);
  await row.getByRole('button', { name: 'Confirm' }).click();
  await expect(row).toContainText('(confirmed)');

  await row.getByRole('button', { name: 'Mark No-Show' }).click();

  await expect(row).toContainText('(confirmed)');
  await expect(row.getByRole('button', { name: 'Mark No-Show' })).toBeVisible();
});

test('vet can cancel an appointment more than two hours out', async () => {
  // AppointmentTransitionService enforces a 2-hour cancellation cutoff; the
  // earliest offered slot is often inside that window, so pick a later one.
  const reason = `vetcancel-${ts}`;
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason, slotIndex: 10 });

  await vetPage.goto('/vet/appointments');
  const row = appointmentRow(vetPage, reason);
  await row.getByRole('button', { name: 'Cancel' }).click();

  await expect(row).toContainText('(cancelled)');
  await expect(row.getByRole('button', { name: 'Confirm' })).not.toBeVisible();
  await expect(row.getByRole('button', { name: 'Cancel' })).not.toBeVisible();
});

test('vet can record a visit for a confirmed appointment', async () => {
  const reason = `visit-${ts}`;
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason });

  await vetPage.goto('/vet/appointments');
  const row = appointmentRow(vetPage, reason);
  await row.getByRole('button', { name: 'Confirm' }).click();
  await expect(row).toContainText('(confirmed)');

  await row.getByRole('link', { name: 'Record Visit' }).click();
  await expect(vetPage).toHaveURL(/\/vet\/appointments\/\d+\/visit$/);
  await expect(vetPage.getByRole('heading', { name: 'Record Visit' })).toBeVisible();

  await vetPage.getByLabel('Diagnosis').fill('Healthy, no issues found.');
  await vetPage.getByLabel('Vaccination').fill('Rabies booster');
  await vetPage.getByLabel('Notes').fill('Follow up in a year.');
  await vetPage.getByRole('button', { name: 'Save Visit' }).click();

  await expect(vetPage).toHaveURL('/vet/appointments');
  await expect(row).toContainText('(completed)');
  await expect(row.getByRole('link', { name: 'Record Visit' })).not.toBeVisible();
});

test('owner can see the recorded visit in their pet visit history', async () => {
  await ownerPage.goto('/owner/visits');

  await expect(ownerPage.getByRole('heading', { name: 'Visit History' })).toBeVisible();
  await expect(ownerPage.getByText(`Dr. ${vetLastName}`)).toBeVisible();
  await expect(ownerPage.getByText(/Healthy, no issues found\./)).toBeVisible();
  await expect(ownerPage.getByText(/Vaccination: Rabies booster/)).toBeVisible();

  await ownerPage.goto('/owner/pets');
  await ownerPage.getByRole('link', { name: petName }).click();

  await expect(ownerPage.getByText(/Healthy, no issues found\./)).toBeVisible();
});
