# Architecture Internals

This document explains the non-obvious design decisions in this application. Each
section describes what a pattern is, why it was chosen, and what breaks if it is not
followed. Where useful, it contrasts the choice with what a framework or ORM — this
project deliberately has neither — would normally decide for you; see
[java-springboot-react-mtier](https://github.com/petclinix/java-springboot-react-mtier)
for the paradigm that makes those choices instead.

---

## 1. Concurrency-Safe Booking: Why a Unique Constraint Alone Isn't Enough

See [`sequence-book-appointment.svg`](sequence-book-appointment.svg) for this
section as a diagram, including both fixes described below.

### The naive version

The obvious way to book an appointment is: check whether the slot is free, then
insert. `Owner\AppointmentController::store()` still does exactly this as a
pre-check, for fast user feedback:

```php
elseif ($vet !== null && !$this->services->appointmentAvailabilityService()->isOfferedSlot($vet->id, $scheduledAt, $durationMinutes)) {
    $errors->add('That time is no longer available. Please choose another.');
}
```

Two concurrent requests for the same slot can both pass this check before either
INSERTs. Neither the check nor the insert alone can fix this — the race lives in the
gap between them.

### First fix: a generated column and a unique constraint

`appointments.active_scheduled_at` is a `STORED` generated column, `NULL` for
cancelled/completed rows:

```sql
active_scheduled_at DATETIME
    GENERATED ALWAYS AS (CASE WHEN status IN ('requested', 'confirmed') THEN scheduled_at ELSE NULL END) STORED,
...
UNIQUE KEY uq_appointments_active_vet_slot (vet_id, active_scheduled_at)
```

InnoDB unique indexes treat multiple `NULL`s as distinct values, so cancelled or
completed rows never collide with each other or with a fresh booking at the same
original time — the slot is genuinely freed. Two concurrent INSERTs for the same
`(vet_id, scheduled_at)` pair are now resolved by the database itself: one succeeds,
one gets a duplicate-key error, with no locking discipline required in application
code.

### Why that stopped being enough

Once appointment duration became variable (`duration_minutes`), two bookings with
*different* start times can still genuinely overlap — 10:00–11:00 and 10:30–11:30,
say. The unique constraint only compares exact `scheduled_at` values; it has no
concept of a time range, so it cannot catch this case at all.

### The real fix: a row lock plus an explicit overlap query

`AppointmentRepository::create()` wraps the check and the insert in one transaction,
first taking a lock on the vet being booked:

```php
public function create(int $petId, int $vetId, DateTimeImmutable $scheduledAt, ?string $reason, int $durationMinutes = 30): Appointment
{
    return Database::runInTransaction(function () use (...): Appointment {
        // Serialize all booking attempts for this vet so the overlap check
        // below and the subsequent insert are atomic together.
        $this->pdo->prepare('SELECT id FROM vets WHERE id = :vet_id FOR UPDATE')->execute(['vet_id' => $vetId]);

        $end = $scheduledAt->modify("+{$durationMinutes} minutes");
        if ($this->hasOverlap($vetId, $scheduledAt, $end)) {
            throw AppointmentSlotUnavailableException::alreadyBooked();
        }

        // ... INSERT, with the duplicate-key catch kept as a backstop ...
    });
}

private function hasOverlap(int $vetId, DateTimeImmutable $start, DateTimeImmutable $end): bool
{
    // scheduled_at < :end AND scheduled_at + INTERVAL duration_minutes MINUTE > :start
}
```

`SELECT ... FOR UPDATE` on the vet's own row means two concurrent booking attempts
for the *same vet* are fully serialized — the second transaction blocks until the
first commits or rolls back, at which point it re-runs `hasOverlap()` against the
now-committed state. This is coarser than locking just the conflicting time range
(it serializes *all* bookings for that vet, not only overlapping ones), but it is
simple and provably correct. Fine-grained range locking would rely on InnoDB gap-lock
semantics, which are subtle and isolation-level-dependent — not something worth
building for this application's scale.

The unique constraint from the first fix is kept, not removed. It is a free,
unconditional backstop: if the locked-overlap logic were ever bypassed by a bug, the
exact-match case is still caught at the database level.

---

## 2. Reentrant Transactions: Why `runInTransaction()` Had to Change

### The requirement that broke the original version

A reschedule is defined as cancel-the-old-appointment-and-book-the-new-one, and it
has to be atomic: if the new slot isn't available, the old appointment must remain
exactly as it was, not left cancelled with nothing to replace it.

`AppointmentTransitionService::rescheduleAsOwner()` wraps both steps in one
transaction:

```php
public function rescheduleAsOwner(int $appointmentId, int $ownerId, DateTimeImmutable $newScheduledAt, int $newDurationMinutes, ?string $reason): Appointment
{
    return Database::runInTransaction(function () use (...): Appointment {
        $appointment = $this->authorizeOwnerCancellation($appointmentId, $ownerId);
        $this->appointments->updateStatus($appointment->id, AppointmentStatus::Cancelled);

        return $this->appointments->create($appointment->petId, $appointment->vetId, $newScheduledAt, $reason, $newDurationMinutes);
    });
}
```

But `AppointmentRepository::create()` — called here as the "rebook" step — already
opens *its own* `Database::runInTransaction()` internally (see §1, for the lock and
overlap check). PDO does not support true nested transactions: calling
`beginTransaction()` while one is already open throws.

### The fix

`Database::runInTransaction()` checks whether a transaction is already open and, if
so, just runs the work inline instead of trying to start a second one — only the
outermost call actually begins, commits, or rolls back:

```php
public static function runInTransaction(callable $work): mixed
{
    $pdo = self::connection();
    $alreadyInTransaction = $pdo->inTransaction();

    if ($alreadyInTransaction) {
        return $work();
    } else {
        return self::doRunInTransaction($work);
    }
}

private static function doRunInTransaction(callable $work): mixed
{
    $pdo = self::connection();
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

If `create()`'s inner call throws (the new slot is unavailable), the exception
propagates up through the inlined inner call to the *outer* `catch`, which rolls
back everything — including the status update the outer function already made. The
existing single-level callers (`AdminService`, `VisitService`, and `create()` used on
its own) are unaffected: for them, `inTransaction()` is always `false`, so behavior
is identical to before. This reentrancy is what makes "compose two independent
transactional operations into one atomic unit" possible without restructuring either
one to know about the other.

---

## 3. Proving Concurrency Without Mocking: The Two-Connection Technique

This codebase's tests run against a real MariaDB instance, never a mock or an
in-memory substitute (see §9). That raises a real question for §1's locking logic:
how do you prove, in a single-threaded PHPUnit process, that two *simultaneous*
requests can't both win?

`Database::newConnection()` exists for exactly this: a fresh, independent PDO
connection, distinct from the memoized singleton `Database::connection()` normally
shares across the whole request. A dedicated test opens two of them and manually
interleaves the sequence a real race would produce:

```php
$connectionA = Database::newConnection();
$connectionB = Database::newConnection();

// A locks the vet row and inserts, but does not commit yet.
$connectionA->beginTransaction();
$connectionA->prepare($lock)->execute(['vet_id' => $this->vet->id]);
$connectionA->prepare($insert)->execute([...]);

// B must not be allowed to proceed while A is still in flight. A short
// lock-wait timeout keeps the test fast instead of hanging if this regresses.
$connectionB->exec('SET SESSION innodb_lock_wait_timeout = 2');
$blocked = false;
try {
    $connectionB->beginTransaction();
    $connectionB->prepare($lock)->execute(['vet_id' => $this->vet->id]);
} catch (PDOException) {
    $blocked = true;
}

$connectionA->commit();

self::assertTrue($blocked, 'A second concurrent booking must be blocked, not silently allowed through.');
```

This is deliberately not a real-process race (two PHP CLI processes actually firing
at once). A timing-based race is flaky by nature — it proves the bug *can* happen,
not that it *can't*. This technique instead forces the exact interleaving that
matters (B always attempts its lock while A's transaction is still open) and asserts
on InnoDB's own blocking behavior, so the test is deterministic every run and
exercises the real production locking code path, not a simulation of it.
`tests/Repository/AppointmentRepositoryTest.php` has two variants of this: one for
an exact-slot collision (§1's unique constraint) and one for an overlapping-but-
different-start-time collision (§1's row lock — the case the constraint alone can't
catch).

---

## 4. Recurring Availability: Time-of-Day Values and the Exception-Overrides-Template Rule

### The two entities

A vet's bookable hours are derived from two tables: `availability` (a recurring
weekly template — day-of-week plus a time range) and `availability_exceptions` (a
one-off override for one specific date — either "unavailable" or "custom hours").
Neither stores an actual bookable slot; `AppointmentAvailabilityService::openSlots()`
computes them on read.

### Representing "just a time" in PHP

`starts_at`/`ends_at` are SQL `TIME` columns — no date component. PHP's
`DateTimeImmutable` always carries a date, so a naive `new DateTimeImmutable('09:00')`
silently uses *today's* date, which would make two time-of-day values built at
different moments compare incorrectly. The hydration uses the `!` format-reset
modifier instead:

```php
DateTimeImmutable::createFromFormat('!H:i:s', (string) $row['starts_at']),
```

The `!` resets every field the format string doesn't mention — not just hour/minute/
second — anchoring every time-of-day value to `1970-01-01` consistently, regardless
of when the code runs. Without it, comparisons and arithmetic on these values would
work most of the time and fail in a way that depends on the current date, which is
exactly the kind of bug that hides in development and surfaces at a specific,
hard-to-reproduce moment in production.

### Resolving a specific date

`openSlots()` walks a 60-day lookahead. For each date, one function decides which
window(s), if any, apply:

```php
private function windowsForDate(DateTimeImmutable $date, array $templatesByDay, array $exceptionsByDate): array
{
    $exception = $exceptionsByDate[$date->format('Y-m-d')] ?? null;

    if ($exception !== null) {
        if (!$exception->isAvailable || $exception->startsAt === null || $exception->endsAt === null) {
            return [];
        }
        return [[$this->combine($date, $exception->startsAt), $this->combine($date, $exception->endsAt)]];
    }

    $templates = $templatesByDay[DayOfWeek::fromDate($date)->value] ?? [];
    return array_map(fn ($template) => [$this->combine($date, $template->startsAt), $this->combine($date, $template->endsAt)], $templates);
}
```

An exception for the exact date *fully determines* that date — it either blocks it
entirely or **replaces** the day's hours with its own. It is never additive to the
weekly template. A vet who normally works Mondays 09:00–17:00 but adds a one-off
Monday exception for 14:00–16:00 (a half-day) should not also see their normal
09:00–17:00 hours bleeding through on that date — that would silently double-offer
hours the vet explicitly narrowed. This is a data-modeling correctness rule, not an
implementation detail, and it is the entire reason the `if ($exception !== null)`
branch returns early instead of merging both sources.

---

## 5. A Test-Fixture Bug Caught Before It Shipped: Recurring vs. One-Off in Test Helpers

Extending the availability model (§4) meant ~15 existing test fixtures across 5 files
— all built around `AvailabilityRepository::create($vetId, $start, $end)`, a
one-off concrete window in the old model — needed to keep working under the new
recurring-template model.

The first version of the shared replacement helper looked reasonable:

```php
// REJECTED — recurs on every matching weekday within the 60-day lookahead
private function createAvailabilityWindow(int $vetId, DateTimeImmutable $start, DateTimeImmutable $end): Availability
{
    return (new AvailabilityRepository())->create($vetId, DayOfWeek::fromDate($start), $start, $end);
}
```

This derives a day-of-week from `$start` and stores it as a **weekly template** —
which is precisely the wrong entity for what these tests need. A fixture meant to
represent "the vet has this one specific window, two weeks from now" would, once
`openSlots()` projects it across the full 60-day lookahead, reappear on every other
matching weekday too — roughly eight *additional* occurrences the test never asked
for and its assertions never accounted for. Every count-based assertion in the
ported tests (`self::assertCount(3, $slots)`, and similar) would have started failing
or, worse, silently passing for the wrong reason.

The fix routes through `AvailabilityException` (`isAvailable = true`) instead — a
genuinely one-off, non-recurring window, exactly matching what a "fixture at this one
moment" fixture is supposed to mean:

```php
private function createAvailabilityWindow(int $vetId, DateTimeImmutable $start, DateTimeImmutable $end): AvailabilityException
{
    return (new AvailabilityExceptionRepository())->create($vetId, $start, true, $start, $end);
}
```

The general lesson: when a repository operation has recurring or projected
semantics, a test helper that reaches for the "obviously matching" repository method
purely for a convenient call shape can silently change what the test is actually
proving. Worth a deliberate check — "does this data recur, and if so, does the test
care?" — before a shared helper gets adopted across a dozen call sites.

---

## 6. File Upload Security and the Untestable Function Problem

### Validating what was actually uploaded, not what the client claims

`PetPhotoUploadService::upload()` never trusts `$_FILES['photo']['type']` — a
client-supplied header, trivially spoofed by anyone crafting the request by hand. It
inspects the file's actual bytes instead:

```php
$extension = self::ALLOWED_MIME_TYPES[(string) mime_content_type($file['tmp_name'])] ?? null;
```

The stored filename is never the client-supplied one either — `bin2hex(random_bytes(16))`
plus the *server-detected* extension. A client-controlled filename is both a
collision risk (two uploads named `photo.jpg`) and, depending on how a path is later
built from it, a path-traversal risk (`../../etc/passwd`-shaped input). Generating
the name server-side removes both classes of bug at once, not just the second one.

The move itself uses `move_uploaded_file()`, not a plain `rename()`. PHP's
`move_uploaded_file()` internally verifies the source path was produced by a genuine
PHP-SAPI upload (`is_uploaded_file()`) before touching the filesystem — a narrower
defense-in-depth check on top of everything above, not the primary one.

### Why that correctness choice makes the code hard to test

`is_uploaded_file()` can only ever be true for a file that arrived via a real
HTTP multipart request handled by the PHP SAPI. A PHPUnit test invoking the
controller directly — this codebase's standing convention, no HTTP layer in tests —
can never produce a value that satisfies it. `move_uploaded_file()` will fail every
time it's called from a test, regardless of how correct the surrounding validation
logic is.

The fix is not to weaken the production code. The "mover" is an injected dependency,
defaulting to the real function:

```php
public function __construct(
    private readonly string $uploadDir = __DIR__ . '/../../public/uploads/pets',
    /** @var callable(string, string): bool */
    private readonly mixed $move = 'move_uploaded_file',
) {}
```

`tests/Service/PetPhotoUploadServiceTest.php` constructs the service with `copy(...)`
instead, against real temporary files on disk — this exercises every real
validation branch (size cap, MIME sniffing, filename generation, the actual bytes
written to disk) with nothing faked except the one call that structurally cannot run
outside a real HTTP request. The production `move_uploaded_file()` path itself is
verified by a live `curl -F photo=@...` smoke test during manual verification, not by
an automated test — an explicit, acknowledged gap in automated coverage for one
single-line call, closed by a different, deliberate means rather than by mocking the
filesystem or trusting the code by inspection alone.

---

## 7. Centralizing a Business Rule That Two Different Authorization Models Share

Cancelling an appointment is authorized two different ways depending on who's
asking. A vet is authorized by a direct id match (`appointment->vetId === $vetId`).
An owner is authorized by traversing ownership (`appointment->petId` → that pet's
`ownerId` — the appointment carries no owner id of its own). These are structurally
different checks, written against different repositories.

Both still have to obey the same 2-hour cancellation cutoff. That rule lives in
exactly one place, `AppointmentTransitionService`, checked identically regardless of
which authorization path got there:

```php
if ($to === AppointmentStatus::Cancelled && !$this->isBeforeCutoff($appointment)) {
    return false;   // the vet-authorized path, inside transition()
}
...
if (!$this->isBeforeCutoff($appointment)) {
    throw new AppointmentNotCancellableException();   // the owner-authorized path, inside authorizeOwnerCancellation()
}
```

Because the cutoff check sits in the layer both authorization paths already pass
through — never duplicated per-controller — adding the owner-facing cancel/reschedule
feature required *zero* changes to the existing vet-facing cancel code to make it
respect the same cutoff. The later no-show timing guard followed the identical
shape: one more `if ($to === AppointmentStatus::NoShow && ...)` branch in the same
shared `transition()` method, automatically covering the only path that reaches it
(vet-initiated; no-show has no owner-side equivalent).

The general principle: when a rule must hold for multiple actors with different
authorization models, the check belongs in whatever layer *both* models already
funnel through before touching the data — not copied into each caller.

---

## 8. `ServiceFactory`: A Deliberate Non-Decision

### The problem

Nine `Service` classes, each needing one to four repositories (sometimes another
`Service`) via constructor injection. Left ad hoc, every controller ends up with its
own `new Service(new Repo(), new Repo(), new OtherService(new Repo(), new Repo()))`
cascade — the exact wiring is easy to get subtly wrong twice in two different
controllers, and a change to one service's dependencies means hunting down every
place it was constructed by hand.

### What was considered and rejected

A small reflection-based container was one option on the table —
`Container::get(SomeService::class)` resolving from a `match` expression, with
instance caching. It was rejected: string/class-keyed resolution and a registration
step are a form of the exact "framework magic" this codebase's own framing commits to
not having, disproportionate to nine services in an application this size. It would
also have made the object graph harder to *read* — the wiring exists somewhere, but
not anywhere you can grep to.

### What was chosen

One plain class, `ServiceFactory`, with one method per service. Every method body is
a direct, unconditional `new` expression — no reflection, no registration, no lazy
anything:

```php
public function ownerAppointmentBoardService(): OwnerAppointmentBoardService
{
    return new OwnerAppointmentBoardService(
        new PetRepository(),
        new VetRepository(),
        new AppointmentRepository(),
        $this->appointmentAvailabilityService(),
    );
}
```

Controllers depend on `ServiceFactory` as a single, defaulted constructor parameter
(`ServiceFactory $services = new ServiceFactory()`) rather than the router needing to
know how to build one — see §10 for why the default matters. The entire object graph
for the whole application is readable top-to-bottom in one file.

### The corollary: no matching `ControllerFactory`

The same question came up for controller instantiation and got the opposite answer.
`Router::dispatch()` already is the single place controllers get built
(`new $class($twig)`) — there was no duplicated wiring to centralize, which is the
actual problem `ServiceFactory` solves. And because every controller's new
dependency is added as a *defaulted* constructor parameter, `Router` never has to
change when a controller gains one. Building a `ControllerFactory` anyway would have
been ceremony without a real problem behind it — the litmus test that
`ServiceFactory` itself passed and this one didn't.

---

## 9. Controlling the Blast Radius of a New Column: Default Parameters as a Deliberate Convention

`AppointmentRepository::create()`, `PetRepository::create()`, and
`VisitRepository::create()` are each used as unrelated fixture setup in dozens of
test methods, scattered across many files, that have nothing to do with whatever
specific new field is currently being added.

The convention: a field added to one of these *wide-fixture* `create()` methods is
appended as the **last** parameter with a sensible default, never inserted
positionally or made required:

```php
public function create(int $petId, int $vetId, DateTimeImmutable $scheduledAt, ?string $reason, int $durationMinutes = 30): Appointment
```

`= 30` reproduces the exact behavior every existing fixture already relied on before
duration existed, so none of them needed to change. The one real production call
site (`Owner\AppointmentController::store()`) always passes the value explicitly —
the default only exists to keep unrelated tests compiling, never to paper over a
missing value in a code path that matters.

This is deliberately not a blanket rule. `VisitRepository::create()`'s `vaccination`
parameter, with only two call sites at the time it was added, was inserted as a
plain required parameter in a natural position instead — there was no wide blast
radius to protect against, so a default would have added a footgun (silently
accepting a missing value) without solving a real problem. The convention is applied
in proportion to actual fixture fan-out, checked with a grep for existing call sites
before deciding, not applied reflexively to every new field.

---

## 10. Testing Strategy

### Real database, never a mock

Every test in this codebase — controller, service, and repository — runs against a
live MariaDB instance via Docker Compose. None of the SQL, schema constraints,
generated columns, or hand-rolled hydration logic is ever replaced with a mock or an
in-memory substitute.

This is a deliberate contrast with a mock-heavy, ORM-backed sibling implementation of
this same application. There, an ORM and a framework mediate persistence, so a
repository mock stands in for a well-defined contract the framework itself
guarantees. Here, there is no ORM: the SQL *is* the business logic for concurrency
safety (§1), overlap detection (§4), and constraint enforcement. Mocking the
repository layer in this codebase would mean testing nothing about whether the
actual queries are correct — the one thing most worth testing.

The cost is real: tests are slower (a live database round-trip per query, not an
in-memory fake), need Docker running, and — since there is no automatic per-test
transaction rollback — every test file that creates data cleans it up explicitly in
`tearDown()`:

```php
protected function tearDown(): void
{
    Database::connection()
        ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
        ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
}
```

and every fixture email is suffixed with `bin2hex(random_bytes(6))` so concurrent
test runs (or a prior run's leftover data) can never collide on a unique constraint.

### Controller tests — `tests/{Owner,Vet,Admin,Http}/*ControllerTest.php`

Controller methods are called directly (`$controller->store()`), not through an HTTP
request — no router, no real request/response cycle. `$_POST`/`$_GET`/`$_FILES` are
populated by hand; a `HeaderSpy` test double intercepts `header()` calls so redirects
can be asserted without actually sending headers. These tests cover validation
messages, redirect targets, and authorization (does this action reject another
owner's pet, another vet's appointment). They still run against the real database —
only the HTTP transport is skipped, not persistence.

### Service tests — `tests/Service/*Test.php`

Business-rule tests: overlap detection, cutoff enforcement, transaction rollback on
failure, exception-vs-template resolution. Constructed with real repositories
(`new AppointmentTransitionService(new AppointmentRepository(), new PetRepository())`),
never a mock — the same reasoning as above applies at this layer too.

### Repository tests — `tests/Repository/*Test.php`

Introduced specifically for §1 and §3: DB-constraint and concurrency behavior that
is neither a controller concern (no HTTP-visible behavior) nor cleanly a service
concern (the thing under test is what the database itself enforces, via two raw
connections). This is the one place tests reach for `Database::newConnection()`
directly instead of going through a repository or service.

### Full-stack HTTP tests — `tests/Http/AuthenticatedRoutingTest.php`, `RouterWiringTest.php`

The only tests that exercise a real request/response cycle — a real router
dispatching to a real controller behind real middleware — proving route wiring,
middleware ordering, and role-based redirects end-to-end, the layer the controller
tests above deliberately skip.

### "Portal" tests — `tests/{Owner,Vet}/*PortalTest.php`

A distinct style: a single test method drives a realistic multi-step journey
(register → book → confirm → record a visit) directly against repositories and
services, bypassing controllers and HTTP entirely. Neither a controller test (too
narrow — one action at a time) nor a typical service test (too isolated — one method
at a time) is the right shape for "does an entire user journey hold together
end-to-end at the data layer." This is that shape.

### Manual `curl` verification for what automated tests structurally cannot cover

A small number of code paths cannot be exercised by any PHPUnit test in this
codebase's style, because the thing being verified requires a genuine HTTP request:
`move_uploaded_file()` (§6), and — more generally — the actual rendered HTML a
browser would receive. Each phase of this project's development that touched such a
path was verified with a live `docker compose up` + `curl` (or a real multipart file
upload) session before being considered complete, with the specific reasoning
recorded alongside the code (§6 is the clearest example). This is treated as a
deliberate, named verification step — not a substitute for automated tests where
automated tests are possible, but the correct tool for the narrow cases where they
are not.
