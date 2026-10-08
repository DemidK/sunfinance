# Financial Events API

REST service that accepts financial events and turns them into double-entry accounting
transactions (test assignment).

Events are accepted synchronously (validated, stored, queued) and processed asynchronously
by a Messenger worker, so the HTTP endpoint never waits for business processing.

## Stack

- PHP 8.3 (8.2+ compatible), Symfony 7.3
- PostgreSQL 18, Doctrine ORM 3 + Migrations
- Symfony Messenger with the Doctrine transport (queue lives in PostgreSQL)
- EasyAdmin read-only panel for events and transactions
- PHPUnit 12
- Docker (php-fpm + nginx + postgres + worker)

## Run with Docker

```bash
docker compose up -d --build        # or: make up
```

Open http://localhost:8080 — the welcome page confirms the app is running.
The one-shot `migrate` service applies the Doctrine migrations before `php` and `worker` start,
so the `events`, `transactions` and `messenger_messages` tables exist on first boot.
The `worker` container consumes the queue.

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
php bin/console messenger:consume async -vv   # in a second terminal, or: make worker
```

Open http://127.0.0.1:8000.

Connection string lives in `.env` (`DATABASE_URL`); override it in `.env.local` if your
credentials differ. Tests use `.env.test` and the `fin_events_test` database
(Doctrine appends the `_test` suffix automatically in the `test` environment).

```bash
php bin/phpunit
```

## Admin panel

A read-only EasyAdmin panel lists events (with processing status) and transactions, with
filters by event id, account, direction, type and status. Opening an event shows an **Intake**
block: when the request was received and processed, the sender's IP and user agent, the
request method, URL, headers (`Authorization`, `Cookie` and similar are stored as `[redacted]`)
and the raw JSON body. A **Transactions** action jumps to the postings of that event.

| URL              | Login  | Password |
|------------------|--------|----------|
| `/admin`         | `test` | `test`   |

The user is defined in memory in `config/packages/security.yaml` with a plaintext password.
There is no users table. Create, edit and delete actions are disabled; the API under `/events`
stays public.

## API

### POST /events

Accepts a financial event, stores it with status `pending` together with the full inbound
request (sender IP, user agent, headers, raw body, receive time) in `inbound_requests`,
publishes a `ProcessEventMessage` to the `async` transport and returns immediately. The worker then records the double-entry pair
of accounting transactions and marks the event `processed`.

```bash
curl -i -X POST http://localhost:8080/events \
  -H 'Content-Type: application/json' \
  -d '{"event_id":"evt_123","type":"payment_received","amount":100.00,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}'
```

| Field       | Rules                                                              |
|-------------|--------------------------------------------------------------------|
| `event_id`  | required, string, unique                                           |
| `type`      | `payment_received`, `payment_sent` or `fee_charged`                |
| `amount`    | positive number, stored with 2 decimals                            |
| `currency`  | 3-letter code                                                      |
| `timestamp` | ISO 8601 date/time, e.g. `2026-01-01T00:00:00Z`                    |

Postings created per type:

| Type               | Debit                 | Credit                |
|--------------------|-----------------------|-----------------------|
| `payment_received` | `user_account`        | `system_cash_account` |
| `payment_sent`     | `system_cash_account` | `user_account`        |
| `fee_charged`      | `user_account`        | `fee_account`         |

Responses:

| Status | Meaning                                  | Body                                   |
|--------|------------------------------------------|----------------------------------------|
| 202    | accepted and queued                      | empty                                  |
| 400    | request body is not valid JSON           | `{"error": "..."}`                     |
| 422    | validation failed, incl. unknown type    | `{"error": "...", "violations": [...]}`|
| 409    | `event_id` was already processed         | `{"error": "..."}`                     |
| 500    | database or other unexpected failure     | `{"error": "..."}`                     |

Idempotency works on two levels. On intake the `event_id` is unique in the `events` table:
a duplicate is rejected with 409 before anything is queued, and a concurrent duplicate that
slips past that check is rejected by the unique constraint. The event row and the queue message
are written in one database transaction, so an event is never stored without its message or
vice versa. In the worker the handler locks the event row, skips it if already processed, and
writes both transactions plus the status change in one transaction, so a redelivered message
never duplicates postings.

Failed messages are retried 3 times with exponential backoff and then moved to the `failed`
transport (`php bin/console messenger:failed:show`).

### Planned

| Method | Path                             | Description                         |
|--------|----------------------------------|-------------------------------------|
| GET    | `/accounts/{id}/balance`         | Account balance (bonus)             |
| GET    | `/accounts/{id}/transactions`    | Account transactions (bonus)        |

## Project layout

```
docker/            Dockerfile, nginx config, postgres init script
scripts/           local DB bootstrap SQL
src/Controller     HTTP layer, admin panel under Controller/Admin
src/Dto            request payloads with validation constraints
src/Entity         Doctrine entities
src/Enum           event types, accounts, directions and posting rules
src/EventListener  exception to HTTP response mapping
src/Exception      domain exceptions
src/Message        queue messages
src/MessageHandler Messenger handlers (worker side)
src/Repository     Doctrine repositories
src/Service        intake and transaction recording
src/Twig           Twig helpers for the admin panel
src/Validator      custom constraints
migrations/        Doctrine migrations
tests/             PHPUnit tests
```
