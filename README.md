# php-twig-mtier

PetcliniX — classic server-rendered PHP MVC showcase: Twig templates, a hand-rolled PDO data layer, no ORM.
See [`plan.md`](plan.md) for the full build plan and paradigm framing; a detailed README (including the "no ORM" trade-off writeup) lands in Phase 6.

## Quickstart

```
docker compose up --build
```

Then visit http://localhost:8080.

## Running tests

```
docker compose exec php vendor/bin/phpunit
```
