# Financial Events API

REST service that accepts financial events and turns them into double-entry accounting
transactions (test assignment).

## Stack

- PHP 8.3 (8.2+ compatible), Symfony 7.3
- PostgreSQL 18, Doctrine ORM 3 + Migrations
- PHPUnit 12
- Docker (php-fpm + nginx + postgres)

## Run with Docker

```bash
docker compose up -d --build        # or: make up
docker compose exec php php bin/console doctrine:migrations:migrate -n
```

Open http://localhost:8080 — the welcome page confirms the app is running.

Ports can be changed with env vars: `HTTP_PORT` (default 8080), `POSTGRES_PORT` (default 5433, host side).
The `database` container creates both `fin_events` and `fin_events_test` databases on first start.

Run tests inside the container:

```bash
docker compose exec php php bin/phpunit   # or: make test
```

## Run locally (without Docker)

Requirements: PHP 8.2+ with `pdo_pgsql`, `intl`; Composer; a local PostgreSQL server.

```bash
composer install

# one-time: create role + databases on the local cluster
sudo -u postgres psql -f scripts/local-db-init.sql

php bin/console doctrine:migrations:migrate -n
php -S 127.0.0.1:8000 -t public     # or: make serve
```

Open http://127.0.0.1:8000.

Connection string lives in `.env` (`DATABASE_URL`); override it in `.env.local` if your
credentials differ. Tests use `.env.test` and the `fin_events_test` database
(Doctrine appends the `_test` suffix automatically in the `test` environment).

```bash
php bin/phpunit
```

## API

| Method | Path                             | Description                         |
|--------|----------------------------------|-------------------------------------|
| POST   | `/events`                        | Accept a financial event            |
| GET    | `/accounts/{id}/balance`         | Account balance (bonus)             |
| GET    | `/accounts/{id}/transactions`    | Account transactions (bonus)        |

Status codes: `204` success, `400` invalid JSON, `422` validation error,
`409` duplicate `event_id`, `500` unexpected error.

## Project layout

```
docker/            Dockerfile, nginx config, postgres init script
scripts/           local DB bootstrap SQL
src/Controller     HTTP layer
src/Entity         Doctrine entities
src/Repository     Doctrine repositories
migrations/        Doctrine migrations
tests/             PHPUnit tests
```
