# php-twig-mtier

PetcliniX — classic server-rendered PHP MVC showcase: Twig templates, a hand-rolled PDO data layer, no ORM.

## Overview

A thin layered architecture, `Http\Controller → Service → Repository → Domain`,
with hand-rolled PDO instead of an ORM — the SQL a query runs *is* the code
you're reading, with no generated queries or lazy-loading bug class. A
**Service** exists only when logic genuinely spans multiple repositories or
must be enforced identically across authorization models; otherwise a
controller talks to a Repository directly. This is the main point of contrast
with the sister Java implementation (`java-springboot-react-mtier`), where
every controller action is forced through a service layer. See
[`docs/architecture.md`](docs/architecture.md) for the full layer rules,
constraints, and conventions.

## Documentation & Diagrams

| Doc | What it covers |
| --- | --- |
| [`docs/architecture.md`](docs/architecture.md) | The structural reference: package layout, layer responsibilities, design constraints, code conventions, the no-ORM trade-off, and what's intentionally not here. |
| [`docs/architecture-internals.md`](docs/architecture-internals.md) | Non-obvious design decisions — concurrency-safe booking, reentrant transactions, recurring-availability resolution, file-upload security, testing strategy — each as a problem/why-it-breaks/solution writeup with real code. |
| [`docs/c4-layer-architecture.puml`](docs/c4-layer-architecture.puml) / [`.svg`](docs/c4-layer-architecture.svg) | The request path from client through Router, Middleware, Controllers, Services, Repositories, Domain, to MariaDB. |
| [`docs/layer-dependencies.puml`](docs/layer-dependencies.puml) / [`.svg`](docs/layer-dependencies.svg) | Allowed vs. forbidden edges between layers — `architecture.md`'s Design Constraint #1, as a diagram. |
| [`docs/service-composition.puml`](docs/service-composition.puml) / [`.svg`](docs/service-composition.svg) | Every controller, which service (if any) it calls via `ServiceFactory`, and which repositories each service holds. |
| [`docs/entity-model.puml`](docs/entity-model.puml) / [`.svg`](docs/entity-model.svg) | All 9 tables, their columns, and their relationships, sourced from `db/schema.sql`. |
| [`docs/sequence-book-appointment.puml`](docs/sequence-book-appointment.puml) / [`.svg`](docs/sequence-book-appointment.svg) | The booking flow, including both concurrency-safety layers described in `architecture-internals.md` §1. |

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

## Quality Tooling

- `composer run-script cs-check` / `cs-fix` — php-cs-fixer, PER-CS2x0 base.
- `composer run-script phpstan` — level 8 static analysis over `src/`
  (`phpstan-baseline.neon` ignores a handful of pre-existing issues; all new
  code is still checked at level 8).
- `composer run-script deptrac` — validates only Design Constraint #1's
  dependency-direction graph (Domain/Infrastructure depend on nothing;
  Repository → Domain+Infrastructure; Service → Domain+Repository+Infrastructure;
  Controller → Domain+Repository+Service+supporting HTTP pieces). Design
  Constraints 2–7 are not dependency-graph rules and are **not** checked by any
  tool here — they remain enforced by code review.
- `composer run-script lint` runs all three (non-mutating); CI runs the same
  three checks on every push/PR via the `lint` job.

### Running php-cs-fixer with Docker

```
./composer.sh run-script cs-check   # check only, no changes written — what CI runs
./composer.sh run-script cs-fix     # apply fixes in place
```

`phpstan` and `deptrac` follow the same pattern:

```
./composer.sh run-script phpstan
./composer.sh run-script deptrac
./composer.sh run-script lint       # all three, non-mutating
```

Under the hood `composer.sh` is just:

```
docker compose run --rm --no-deps php composer "$@"
```

`--no-deps` skips starting the `db` service — none of these tools touch the
database, and skipping it avoids fighting over the host's `3306` port with
any other project's db container. If the full stack is already running
(`docker compose up`), `docker compose exec php composer run-script cs-fix`
works too.

## Building the production image

`docker/php/Dockerfile` is a multi-stage build. `docker-compose.yml` targets the `testing`
stage (PHPUnit + `tests/`, for local dev). `prod` is the last stage — the default target —
and installs with `--no-dev`, ships no test sources, and has no PHPUnit binary:

```
docker build --target prod -f docker/php/Dockerfile -t php-twig-mtier:prod .
```
