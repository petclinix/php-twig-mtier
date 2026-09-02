import { Page, expect } from '@playwright/test';

export interface BookAppointmentOptions {
  vetLabel: string;
  petLabel: string;
  reason: string;
  durationMinutes?: number;
  /**
   * Which offered slot to pick (option index within the "Date and time"
   * select; index 0 is the "Select a time" placeholder). Defaults to 1 (the
   * earliest open slot).
   *
   * AppointmentTransitionService enforces a 2-hour cancellation cutoff, and
   * the earliest open slot returned by AppointmentAvailabilityService is
   * often well inside that window (it starts scanning from "now"). Pass a
   * later index (e.g. 10, ~5 hours out against the default 30-minute grid)
   * for any appointment the test will go on to cancel or reschedule.
   */
  slotIndex?: number;
}

/**
 * Assumes `page` is logged in as an OWNER and is on (or navigates to)
 * /owner/appointments. Booking is a two-step, full-page-reload flow:
 * choosing a vet (+ duration) fetches that vet's open slots via a GET, then a
 * second form books against one of them. Each booking lands on a unique slot
 * since AppointmentAvailabilityService excludes slots that are already
 * booked, so tests never collide with each other.
 */
export async function bookAppointment(page: Page, options: BookAppointmentOptions): Promise<void> {
  await page.goto('/owner/appointments');
  await page.getByLabel('Vet').selectOption({ label: options.vetLabel });
  if (options.durationMinutes) {
    await page.getByLabel('Duration').selectOption(String(options.durationMinutes));
  }
  await page.getByRole('button', { name: 'Show available times' }).click();

  await page.getByLabel('Pet').selectOption({ label: options.petLabel });
  const slotSelect = page.getByLabel('Date and time');
  const slotIndex = options.slotIndex ?? 1;
  // Full-page reload (not an async fetch) — options are already rendered by
  // the time the click above resolves, so a plain count read is enough.
  const optionsCount = await slotSelect.locator('option').count();
  expect(optionsCount).toBeGreaterThan(slotIndex);
  await slotSelect.selectOption({ index: slotIndex });
  await page.getByLabel('Reason').fill(options.reason);
  await page.getByRole('button', { name: 'Book Appointment' }).click();

  // Success redirects to /owner/appointments; a validation failure re-renders
  // the same URL, so the reliable signal is the new row showing up.
  await expect(appointmentRow(page, options.reason)).toBeVisible({ timeout: 5_000 });
}

/** The `<li>` for the appointment identified by its (unique) reason text. */
export function appointmentRow(page: Page, reason: string) {
  return page.getByRole('listitem').filter({ hasText: reason });
}
