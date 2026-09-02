import { test, expect, type Page } from '@playwright/test';
import { registerUser } from '../helpers/auth';
import { ensureVetIsAlwaysOpen } from '../helpers/availability';
import { appointmentRow, bookAppointment } from '../helpers/appointments';

/**
 * Owner appointment tests: book (vet -> duration -> live slot list -> pet ->
 * slot -> reason), list, reschedule, cancel.
 *
 * One vet (with weekly availability spanning every day) and one owner (with
 * one pet) are registered once in beforeAll and reused for every test in
 * this file — see the rate-limit note in helpers/auth.ts. Appointments never
 * collide with each other because AppointmentAvailabilityService excludes
 * already-booked slots, so each booking in this file automatically lands on
 * a different time; tests disambiguate "their" appointment by a unique
 * `reason` string.
 */

test.describe.configure({ mode: 'serial' });

const ts = Date.now();
const password = 'testpass123';
const vetEmail = `appt_vet_${ts}@e2e.test`;
const ownerEmail = `appt_owner_${ts}@e2e.test`;
const vetLastName = `Vet${ts}`;
const vetLabel = `Dr. E2E ${vetLastName} (General Practice)`;
const petName = `TestPet_${ts}`;

let ownerPage: Page;

test.beforeAll(async ({ browser }) => {
  const vetPage = await browser.newPage();
  await registerUser(vetPage, vetEmail, password, 'vet', {
    firstName: 'E2E',
    lastName: vetLastName,
    specialty: 'General Practice',
  });
  await ensureVetIsAlwaysOpen(vetPage);
  await vetPage.close();

  ownerPage = await browser.newPage();
  await registerUser(ownerPage, ownerEmail, password, 'owner');
  await ownerPage.goto('/owner/pets');
  await ownerPage.getByLabel('Name').fill(petName);
  await ownerPage.getByLabel('Species').fill('Dog');
  await ownerPage.getByRole('button', { name: 'Add Pet' }).click();
  await expect(ownerPage.getByRole('link', { name: petName })).toBeVisible();
});

test.afterAll(async () => {
  await ownerPage.close();
});

test('appointments page shows empty state initially', async () => {
  await ownerPage.goto('/owner/appointments');

  await expect(ownerPage.getByRole('heading', { name: 'My Appointments' })).toBeVisible();
  await expect(ownerPage.getByText('No appointments yet.')).toBeVisible();
  await expect(ownerPage.getByText('Add a pet before booking an appointment.')).not.toBeVisible();
});

test('booking page lists the registered vet and, once chosen, offers open times', async () => {
  await ownerPage.goto('/owner/appointments');

  await expect(ownerPage.getByLabel('Vet').getByRole('option', { name: vetLabel })).toHaveCount(1);

  await ownerPage.getByLabel('Vet').selectOption({ label: vetLabel });
  await ownerPage.getByRole('button', { name: 'Show available times' }).click();

  await expect(ownerPage.getByLabel('Pet')).toBeVisible();
  const slotSelect = ownerPage.getByLabel('Date and time');
  await expect(slotSelect.locator('option')).not.toHaveCount(1);
});

test('owner can book an appointment', async () => {
  const reason = `booking-${ts}`;

  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason });

  const row = appointmentRow(ownerPage, reason);
  await expect(row).toContainText('(requested)');
  await expect(row).toContainText(petName);
  await expect(row).toContainText(`Dr. ${vetLastName}`);
  await expect(row.getByRole('link', { name: 'Reschedule' })).toBeVisible();
  await expect(row.getByRole('button', { name: 'Cancel' })).toBeVisible();
});

test('booking without picking a time slot is blocked by native validation', async () => {
  await ownerPage.goto('/owner/appointments');
  await ownerPage.getByLabel('Vet').selectOption({ label: vetLabel });
  await ownerPage.getByRole('button', { name: 'Show available times' }).click();
  await ownerPage.getByLabel('Pet').selectOption({ label: petName });

  await ownerPage.getByRole('button', { name: 'Book Appointment' }).click();

  await expect(ownerPage.getByLabel('Date and time')).toBeFocused();
});

test('owner can reschedule an appointment to a different time', async () => {
  const reason = `resched-${ts}`;
  // Rescheduling cancels the original booking under the hood, which is only
  // allowed more than 2 hours before its scheduled time (see bookAppointment's
  // slotIndex doc) — pick a slot well past that cutoff.
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason, slotIndex: 10 });

  const row = appointmentRow(ownerPage, reason);
  const originalText = await row.textContent();

  await row.getByRole('link', { name: 'Reschedule' }).click();
  await expect(ownerPage.getByRole('heading', { name: 'Reschedule Appointment' })).toBeVisible();

  const slotSelect = ownerPage.getByLabel('New date and time');
  await expect(slotSelect.locator('option')).not.toHaveCount(1);
  await slotSelect.selectOption({ index: 1 });
  await ownerPage.getByRole('button', { name: 'Confirm Reschedule' }).click();

  await expect(ownerPage).toHaveURL('/owner/appointments');
  // Rescheduling cancels the original row and creates a new one with the
  // same reason — both are visible in the (unfiltered) list now, so filter
  // out the cancelled one to get back to a single, unambiguous row.
  const newRow = appointmentRow(ownerPage, reason).filter({ hasNotText: '(cancelled)' });
  const updatedText = await newRow.textContent();
  expect(updatedText).not.toBe(originalText);
  await expect(newRow).toContainText('(requested)');
});

test('owner can cancel Instead from the reschedule page', async () => {
  // Not "resched-cancel-${ts}": that would be a substring of nothing else
  // here, but "cancel-${ts}" below WOULD be a substring of it, and
  // appointmentRow's hasText filter is a substring match — any reason in
  // this file must not be a substring of, or contain as a substring, any
  // other reason used here.
  const reason = `cancelinstead-${ts}`;
  // Cancelling requires the appointment to be more than 2 hours out — see
  // bookAppointment's slotIndex doc.
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason, slotIndex: 10 });

  const row = appointmentRow(ownerPage, reason);
  await row.getByRole('link', { name: 'Reschedule' }).click();
  await expect(ownerPage.getByRole('heading', { name: 'Reschedule Appointment' })).toBeVisible();

  await ownerPage.getByRole('button', { name: 'Cancel Appointment Instead' }).click();

  await expect(ownerPage).toHaveURL('/owner/appointments');
  await expect(row).toContainText('(cancelled)');
  await expect(row.getByRole('button', { name: 'Cancel' })).not.toBeVisible();
});

test('owner can cancel a booked appointment from the list', async () => {
  const reason = `cancel-${ts}`;
  // Cancelling requires the appointment to be more than 2 hours out — see
  // bookAppointment's slotIndex doc.
  await bookAppointment(ownerPage, { vetLabel, petLabel: petName, reason, slotIndex: 10 });

  const row = appointmentRow(ownerPage, reason);
  await row.getByRole('button', { name: 'Cancel' }).click();

  await expect(ownerPage).toHaveURL('/owner/appointments');
  await expect(row).toContainText('(cancelled)');
  await expect(row.getByRole('link', { name: 'Reschedule' })).not.toBeVisible();
});
