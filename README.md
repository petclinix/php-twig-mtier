# php-twig-mtier

PetcliniX — classic server-rendered PHP MVC showcase: Twig templates, a hand-rolled PDO data layer, no ORM.

## Documentation & Diagrams

| Doc | What it covers |
| --- | --- |
| [`docs/architecture-internals.md`](docs/architecture-internals.md) | Non-obvious design decisions — concurrency-safe booking, reentrant transactions, recurring-availability resolution, file-upload security, testing strategy — each as a problem/why-it-breaks/solution writeup with real code. |
| [`docs/c4-layer-architecture.puml`](docs/c4-layer-architecture.puml) / [`.svg`](docs/c4-layer-architecture.svg) | The request path from client through Router, Middleware, Controllers, Services, Repositories, Domain, to MariaDB. |
| [`docs/layer-dependencies.puml`](docs/layer-dependencies.puml) / [`.svg`](docs/layer-dependencies.svg) | Allowed vs. forbidden edges between layers — Design Constraint #1 below, as a diagram. |
| [`docs/service-composition.puml`](docs/service-composition.puml) / [`.svg`](docs/service-composition.svg) | Every controller, which service (if any) it calls via `ServiceFactory`, and which repositories each service holds. |
| [`docs/entity-model.puml`](docs/entity-model.puml) / [`.svg`](docs/entity-model.svg) | All 9 tables, their columns, and their relationships, sourced from `db/schema.sql`. |
| [`docs/sequence-book-appointment.puml`](docs/sequence-book-appointment.puml) / [`.svg`](docs/sequence-book-appointment.svg) | The booking flow, including both concurrency-safety layers described in `architecture-internals.md` §1. |

## Concept

A thin layered architecture: `Http\Controller → Service → Repository → Domain`.

A **Service** exists only when logic spans multiple repositories/aggregates, or
when a rule must be enforced identically across different authorization models
(see `architecture-internals.md` §7 for a worked example). Otherwise, a controller
talks to a Repository directly — there is no rule requiring every controller to go
through a service.

This is the one concept most different from the sister Java implementation
(`java-springboot-react-mtier`): there, `web → persistence` is forbidden outright,
so every controller action goes through a service even for a single `findById()`.
Here it's allowed, and used deliberately — most controller actions in this
codebase are simple CRUD with no cross-cutting logic, and routing them through a
pass-through service would just be ceremony.

## Package Structure

```
src/
├── Domain/            plain value objects & enums — zero outbound dependencies
├── Repository/         the only place SQL appears; hydrates Domain objects
│   └── Exception/
├── Service/            cross-repository / cross-authorization-model logic
│   └── Exception/
├── Http/
│   ├── Controller/
│   │   ├── Owner/
│   │   ├── Vet/
│   │   └── Admin/
│   ├── Middleware/      Auth / Owner / Vet / Admin role gates
│   ├── Router/
│   └── Validation/      ErrorBag / Input / Validate
└── Infrastructure/      the single PDO connection + transaction point
```

See [`docs/c4-layer-architecture.svg`](docs/c4-layer-architecture.svg) for the
same structure as a diagram, with the actual request path drawn through it.

## Layer Responsibilities

- **Controller** — the HTTP contract: reads `$_POST`/`$_GET`/`$_FILES`, validates
  input, delegates to a Service or Repository, renders a Twig template or
  redirects. No SQL, no business rules.
- **Service** — logic that genuinely spans multiple repositories, or a rule that
  must hold the same way regardless of which role is calling it. Nothing else
  lives here.
- **Repository** — the only place SQL appears. Returns fully-hydrated Domain
  objects (or `null`/an empty `list`) — never a raw row array.
- **Domain** — plain `final readonly class`es and enums. No PDO, no Twig, no HTTP
  awareness, zero outbound dependencies onto any other layer.
- **Infrastructure** — the single `Database` class holding the PDO connection and
  `runInTransaction()`.

## Design Constraints

**1. Dependency direction is one-way**, with one documented exception (see
[`docs/layer-dependencies.svg`](docs/layer-dependencies.svg)):

- `Domain` depends on nothing.
- `Service` never depends on `Http\Controller`.
- `Repository` never depends on `Service`.
- `Http\Controller` **may** depend on `Repository` directly, for simple CRUD —
  this is the deliberate divergence from the sister project.

**2. Domain objects are framework-free.** No PDO types, no Twig helpers, no
`$_SERVER`/`$_POST` — a `Domain` class only ever holds and computes over its own
data.

**3. All SQL lives in a Repository.** A Service must never build or execute raw
SQL itself, even when a query exists only to serve one Service's business rule —
e.g. `AppointmentRepository::hasOverlap()` exists because
`AppointmentAvailabilityService` needs it, but the query itself still lives in the
Repository.

**4. A Service exists only when justified.** Concrete examples of controllers that
correctly bypass a service entirely: `Owner\PetController` (talks straight to
`PetRepository`/`VisitRepository`), `Vet\AvailabilityController` (straight to
`AvailabilityRepository`/`AvailabilityExceptionRepository`), `Admin\StatsController`
(straight to `StatsRepository`). See
[`docs/service-composition.svg`](docs/service-composition.svg) for the full graph
of which controllers use a service and which don't.

**5. Every controller reaches a Service only via `ServiceFactory`** — never
`new SomeService(new SomeRepo(), ...)` directly inside a controller. See
`architecture-internals.md` §8 for why (single place to see and change service
wiring, no reflection/DI-container magic).

**6. Persistence exceptions never cross into a controller.** A Repository catches
`PDOException` and re-throws a domain exception —
`AppointmentSlotUnavailableException`, etc. A controller only ever catches the
domain exception type, never `PDOException`. This is a genuine, already-followed
parallel to the sister project's JPA-exception-wrapping rule.

**7. There is no lazy loading, because there is no ORM.** Every `findX()` call
returns fully-hydrated Domain objects (or nothing) — there is no proxy object, no
N+1 query risk, no `LazyInitializationException`-shaped bug class. Worth stating
plainly: this entire category of bug simply cannot occur here.

## Code Conventions

**1. Repository method naming** — `create(...)`, `findById(...): ?T`,
`findAllByX(...): list<T>` (never `null` — an empty list on no match), `updateX(...)`,
`delete(...)`. There is no `retrieve*`-throws-`NotFoundException` convention here
(unlike the sister project) — every caller checks `null` explicitly, because there
is no centralized exception-to-HTTP-status mapper to make a shared throwing
convention pay for itself.

**2. `create()` methods grow by trailing optional parameters.** Widely-used
`create()` methods (e.g. `AppointmentRepository::create()`) append new fields as
trailing *optional* parameters rather than an options object, proportional to how
many call sites actually exist — see `architecture-internals.md` §9 for the
reasoning and when that stops being the right call.

**3. Domain-rule exceptions extend `RuntimeException` directly** — no shared
abstract base class, unlike the sister's `PetclinixException`. There is no
centralized handler to make a shared base pay for itself; each controller catches
its own specific exception types inline.

**4. Every mutating controller action follows the same shape:**

```php
$errors = new ErrorBag();
// ...Validate::... calls append to $errors...
if ($errors->isEmpty()) {
    // mutate via Service/Repository, then redirect
} else {
    // re-render the same template with errors + old ($_POST)
}
```

**5. Test fixtures use unique emails** (`bin2hex(random_bytes(6))` suffixes) to
avoid collisions against a real database, and tests follow an `// arrange` /
`// act` / `// assert` comment convention — the latter a direct match to the
sister project's own convention.

## The "no ORM" trade-off

Hand-rolled PDO instead of an ORM/query builder is this project's headline
paradigm choice, contrasting directly with the sister Java implementation's
Spring Data JPA. What it buys:

- The SQL a query runs *is* the code you're reading — no generated queries, no
  N+1 surprises, no lazy-loading bug class (Design Constraint #7).
- No metamodel/annotation-processor build step; no entity-manager lifecycle to
  reason about.
- Schema changes are a plain `.sql` file, reviewable as a diff like any other code.

What it costs:

- Every query is hand-written — no automatic dirty-checking, no
  `save()`-figures-it-out. `Repository::update*` methods write their own `UPDATE`
  statements.
- No rollback tooling for `db/schema.sql` — migrations forward-only, by convention
  rather than framework enforcement.
- Cross-cutting persistence concerns (optimistic locking, auditing columns) are
  each implemented by hand per table that needs them, rather than inherited from
  a base entity.

This is a deliberate portfolio contrast point with `java-springboot-react-mtier`,
not an oversight — see the PetcliniX site's implementation-comparison table.

## Testing

- **Controller tests** — `tests/{Http,Owner,Vet,Admin}/*ControllerTest.php`.
- **Service tests** — `tests/Service/*Test.php`.
- **Repository tests** — `tests/Repository/*Test.php`.
- All tests run against a real database — nothing is mocked (see
  `architecture-internals.md` §10 for why).

```
docker compose exec php vendor/bin/phpunit
```

## What Is Intentionally Not Here

- **No ORM** — the headline paradigm choice; see "The no ORM trade-off" above.
- **No DI container** — services are wired by hand in `ServiceFactory`; see
  Design Constraint #5.
- **No request/response DTOs** — Domain objects render straight to Twig; there is
  no API here to serialize a separate response shape for.
- **No centralized `retrieve*`-throws convention** — see Code Conventions #1.
- **No migration framework** — `db/schema.sql` is plain, forward-only SQL.

## Quickstart

```
docker compose up --build
```

Then visit http://localhost:8080.

Owners and vets self-register at `/register`. The admin account is seeded (admins aren't self-registered):
`admin@petclinix.local` / `admin12345`.

## Running tests

```
docker compose exec php vendor/bin/phpunit
```

## Building the production image

`docker/php/Dockerfile` is a multi-stage build. `docker-compose.yml` targets the `testing`
stage (PHPUnit + `tests/`, for local dev). `prod` is the last stage — the default target —
and installs with `--no-dev`, ships no test sources, and has no PHPUnit binary:

```
docker build --target prod -f docker/php/Dockerfile -t php-twig-mtier:prod .
```
