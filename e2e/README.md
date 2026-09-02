# PetcliniX — E2E Tests

Playwright end-to-end tests for the full PetcliniX stack. Tests run against the running Docker Compose environment (Nginx on port 8080).

---

## Prerequisites

- Node.js 18+
- The full stack running: `docker compose up --build -d` from the repo root

---

## Run

```bash
cd e2e
npm install
npm test                                        # all tests, headless Chromium
npx playwright test tests/1-auth.spec.ts        # single file
npx playwright test --headed                    # watch the browser
npx playwright test --ui                        # interactive Playwright UI
npx playwright show-report                      # open last HTML report
```

Tests run **sequentially** (`workers: 1`) because all tests share a single MariaDB instance, and because `/login` and `/register` share an nginx rate-limit zone (see below) — parallel runs would both collide on test data and burn through that budget even faster.

---

## Structure

```
e2e/
├── playwright.config.ts       # base URL, browser, reporter config
├── helpers/
│   ├── auth.ts                # registerUser()/loginAs(), plus rate-limit-retrying attemptRegister()/attemptLogin()
│   ├── availability.ts        # ensureVetIsAlwaysOpen() — gives a vet a bookable weekly schedule
│   └── appointments.ts        # bookAppointment() and appointmentRow() — shared owner/vet booking flow
└── tests/
    ├── 1-auth.spec.ts         # Registration, login, dashboard-per-role, logout, auth guard
    ├── 2-owner-pets.spec.ts   # Pet management (owner)
    ├── 3-owner-appointments.spec.ts  # Booking, rescheduling, cancelling appointments (owner)
    ├── 4-vet-availability.spec.ts    # Weekly availability + exceptions (vet)
    ├── 5-vet-appointments.spec.ts    # Confirm/no-show/cancel, visit documentation (vet + owner)
    └── 6-admin.spec.ts               # Stats dashboard, user management, activity log (admin)
```

---

## Test Data Strategy

Each test file registers its own timestamped users so test runs are isolated from each other:

```typescript
const ts = Date.now();
const ownerEmail = `appt_owner_${ts}@e2e.test`;
const vetEmail   = `appt_vet_${ts}@e2e.test`;
```

Login is by **email**, not username (see `templates/auth/login.html.twig`).

### One login per file, not per test

Every spec file registers its user(s) **once**, in `beforeAll`, and reuses that logged-in `page` (or `Page` instances kept in module-level variables) across every test in the file via `test.describe.configure({ mode: 'serial' })`. Registration already logs the user in and redirects to `/dashboard`, so no separate login step is needed afterwards.

This matters here specifically because `docker/nginx/default.conf` rate-limits `/login` and `/register` to 10 requests/minute with a burst of 5, `nodelay` — logging in fresh for every test (as a naive per-test `beforeEach` would) burns through that budget almost immediately and turns into a wall of `429`s. `helpers/auth.ts`'s `gotoRetrying`/`submitRetrying` retry on a 429 as a safety net, but minimizing hits in the first place keeps the suite fast.

### Appointments never collide

`AppointmentAvailabilityService` excludes already-booked slots from what it offers next, so booking the "next available slot" repeatedly across tests in the same file never double-books — each booking automatically lands on a different time. Tests that need to find "their" appointment again (to reschedule, cancel, confirm, etc.) filter the list by a unique `reason` string rather than by index or position.

### The 2-hour cancellation cutoff

`AppointmentTransitionService` refuses to cancel (as owner or vet) an appointment that starts less than 2 hours from now — and rescheduling cancels the original booking under the hood, so it's subject to the same rule. The *earliest* offered slot is often inside that window (the slot search starts from "now"). Anywhere a test goes on to cancel or reschedule an appointment, `bookAppointment()` is called with a later `slotIndex` (e.g. `10`, ~5 hours out on the default 30-minute grid) instead of the default `1`.

### No-show requires the time to have already passed

`AppointmentTransitionService` only allows the no-show transition once the appointment's scheduled time is in the past, but every slot the booking UI can offer is in the future. `5-vet-appointments.spec.ts` documents the one outcome reachable through the UI: clicking "Mark No-Show" on a not-yet-elapsed confirmed appointment is a no-op (the controller redirects regardless of whether the transition actually happened).

---

## Shared Helpers

```typescript
// helpers/auth.ts
await registerUser(page, email, password, 'owner' | 'vet', overrides?);
await loginAs(page, email, password);
await attemptRegister(page, fields);   // negative-path: fills + submits, no redirect assertion
await attemptLogin(page, email, password);

// helpers/availability.ts
await ensureVetIsAlwaysOpen(page);     // assumes page is logged in as a VET

// helpers/appointments.ts
await bookAppointment(page, { vetLabel, petLabel, reason, durationMinutes?, slotIndex? });
appointmentRow(page, reason);          // locates the <li> for that appointment, owner or vet side
```

---

## Configuration (`playwright.config.ts`)

| Setting | Value |
|---------|-------|
| Base URL | `http://localhost:8080` |
| Browser | Chromium (Desktop Chrome) |
| Workers | 1 (sequential) |
| Retries | 0 |
| Reporting | HTML + list |
| Screenshots | On failure only |
| Traces | On first retry |
