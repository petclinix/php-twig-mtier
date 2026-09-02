import { Page, expect } from '@playwright/test';

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

function capitalize(day: string): string {
  return day.charAt(0).toUpperCase() + day.slice(1);
}

/**
 * Assumes `page` is already logged in as a VET. Adds a weekly recurring
 * availability window covering every day of the week (00:00–23:59), so
 * booking/reschedule flows always find an open slot regardless of which
 * weekday the suite happens to run on or how many days out the appointment
 * is booked (AppointmentAvailabilityService projects 60 days ahead).
 */
export async function ensureVetIsAlwaysOpen(page: Page): Promise<void> {
  await page.goto('/vet/availability');

  for (const day of DAYS) {
    await page.getByLabel('Day of week').selectOption(day);
    // exact: true — the exception form below also has "Starts at (if custom
    // hours)"/"Ends at (if custom hours)", which getByLabel would otherwise
    // substring-match too.
    await page.getByLabel('Starts at', { exact: true }).fill('00:00');
    await page.getByLabel('Ends at', { exact: true }).fill('23:59');
    // exact: true — the exception form's submit button is "Add Exception".
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByText(`${capitalize(day)}: 00:00`)).toBeVisible();
  }
}
