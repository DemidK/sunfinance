# Financial Events API

REST service that accepts financial events and turns them into double-entry accounting
transactions (test assignment).

Events are accepted synchronously (validated, stored, queued) and processed asynchronously
by a Messenger worker, so the HTTP endpoint never waits for business processing.

## Stack

- PHP 8.3 (8.2+ compatible), Symfony 7.3
- PostgreSQL 18, Doctrine ORM 3 + Migrations
- Symfony Messenger with the Doctrine transport (queue lives in PostgreSQL)
- EasyAdmin read-only panel for events, transactions and the notification log
- PHPUnit 12
- Docker (php-fpm + nginx + postgres + worker)

## Installation with Docker

Requires Docker with Compose v2 and free ports `8080` and `5433`.

```bash
git clone https://github.com/DemidK/sunfinance.git fin-events
cd fin-events
make up      # build, start, run migrations
make seed    # wipe and load 30 mock events
make test    # optional
```

| What          | URL                                       | Login           |
|---------------|-------------------------------------------|-----------------|
| App           | http://localhost:8080                     |                 |
| Admin panel   | http://localhost:8080/admin               | `test` / `test` |
| Notifications | http://localhost:8080/admin/notification  | `test` / `test` |

Other ports: `HTTP_PORT=8081 POSTGRES_PORT=5434 make up`.

After `composer.lock` changes: `docker compose up -d --build --renew-anon-volumes`.

## Run locally (without Docker)

Requirements: PHP 8.2+ with `pdo_pgsql`, `intl`, `bcmath`; Composer; a local PostgreSQL server.

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

A read-only EasyAdmin panel lists events (with provider and status `pending`, `processed` or `duplicate`) and transactions, with
filters by event id, provider, account, direction, type and status. Opening an event shows an **Intake**
block: when the request was received and processed, the sender's IP and user agent, the
request method, URL, headers (`Authorization`, `Cookie` and similar are stored as `[redacted]`)
and the raw JSON body. A **Transactions** action jumps to the postings of that event.

The **Notifications** section lists every request that reached `/events/*`, accepted or not, with the
response that was sent back. Status badges are green for 2xx, yellow for 4xx and red for 5xx. Filters
cover response status, accepted or rejected, URL, sender IP and receive time. Opening a notification
shows the request (headers, raw body) and the response (status, JSON body). Accepted notifications
link to their event.

| URL              | Login  | Password |
|------------------|--------|----------|
| `/admin`         | `test` | `test`   |

The user is defined in memory in `config/packages/security.yaml` with a plaintext password.
There is no users table. Create, edit and delete actions are disabled; the API under `/events`
stays public.

## API

### POST /events/{provider}

Notifications come from different providers with different bodies, so the provider is part
of the URL: `bank` or `card`. Any other value gives 404. Each provider has its own set of
rules; a body that passes them is stored with status `pending` together with the full inbound
request (sender IP, user agent, headers, raw body, receive time) in `inbound_requests`, a
`ProcessEventMessage` is published to the `async` transport and the request returns at once.
Intake performs no lookups: nothing is compared against existing rows, so a repeated id is
accepted and stored as a new row. The worker then records the double-entry pair of accounting
transactions and marks the event `processed`, or marks it `duplicate` when postings for that
id already exist.

#### POST /events/bank

```bash
curl -i -X POST http://localhost:8080/events/bank \
  -H 'Content-Type: application/json' \
  -d '{"event_id":"evt_123","type":"payment_received","amount":100.00,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}'
```

| Field       | Rules                                                              |
|-------------|--------------------------------------------------------------------|
| `event_id`  | required, string, up to 255 characters                             |
| `type`      | `payment_received`, `payment_sent` or `fee_charged`                |
| `amount`    | positive number in major units, stored with 2 decimals             |
| `currency`  | 3-letter code                                                      |
| `timestamp` | ISO 8601 date/time, e.g. `2026-01-01T00:00:00Z`                    |

#### POST /events/card

```bash
curl -i -X POST http://localhost:8080/events/card \
  -H 'Content-Type: application/json' \
  -d '{"transaction":"1z12f32asddas3sdf4","status":"received","amount":10000,"currency":"EUR","time":"2026-01-01 00:00:00"}'
```

| Field         | Rules                                                                  |
|---------------|------------------------------------------------------------------------|
| `transaction` | required, string, up to 255 characters; stored as the event id         |
| `status`      | `received`, `sent` or `fee`                                            |
| `amount`      | positive integer in cents; `10000` is stored as `100.00`               |
| `currency`    | 3-letter code                                                          |
| `time`        | `YYYY-MM-DD HH:MM:SS`, treated as UTC (ISO 8601 is accepted as well)   |

Card statuses map to event types: `received` is `payment_received`, `sent` is `payment_sent`,
`fee` is `fee_charged`. The mapping lives in `src/Dto/Payload/CardPayload.php`.

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
| 404    | unknown provider                         | `{"error": "..."}`                     |
| 422    | provider rules failed                    | `{"error": "...", "violations": [...]}`|
| 500    | database or other unexpected failure     | `{"error": "..."}`                     |

Idempotency lives entirely in the worker. The event row and the queue message are written in
one database transaction, so an event is never stored without its message or vice versa. The
handler locks the event row, skips it unless it is still `pending`, takes a Postgres advisory
lock on the event id, and checks whether postings for that id already exist. If they do, the
event is marked `duplicate` and no postings are written; otherwise both transactions and the
status change are written in one transaction. A unique index on
`transactions (event_id, direction)` backs this at the database level, so neither a redelivered
message nor a repeated id can ever duplicate postings. Event ids share one namespace across
providers.

Failed messages are retried 3 times with exponential backoff and then moved to the `failed`
transport (`php bin/console messenger:failed:show`).

### GET /accounts/{id}/balance

Returns the balance of an account, grouped by currency. `{id}` is one of `user_account`,
`system_cash_account` or `fee_account`; any other value gives 404. The balance is the sum of
debit postings minus the sum of credit postings, so it can be negative. Accounts without
postings return an empty `balances` list.

```bash
curl -s http://localhost:8080/accounts/user_account/balance
```

```json
{
  "account": "user_account",
  "balances": [
    {"currency": "EUR", "debit": "107.25", "credit": "40.50", "balance": "66.75"},
    {"currency": "USD", "debit": "20.00", "credit": "0.00", "balance": "20.00"}
  ]
}
```

### GET /accounts/{id}/transactions

Returns the postings of an account, newest first, with offset pagination.

| Query parameter | Rules                                   | Default |
|-----------------|-----------------------------------------|---------|
| `limit`         | integer from 1 to 200                   | 50      |
| `offset`        | integer, 0 or more                      | 0       |
| `currency`      | 3-letter code, case-insensitive         | all     |
| `direction`     | `debit` or `credit`                     | both    |

```bash
curl -s 'http://localhost:8080/accounts/user_account/transactions?limit=2&direction=debit'
```

```json
{
  "account": "user_account",
  "total": 3,
  "limit": 2,
  "offset": 0,
  "items": [
    {"id": 7, "event_id": "evt_4", "direction": "debit", "amount": "20.00", "currency": "USD", "created_at": "2026-10-11T09:15:02+00:00"},
    {"id": 5, "event_id": "evt_3", "direction": "debit", "amount": "7.25", "currency": "EUR", "created_at": "2026-10-11T09:14:40+00:00"}
  ]
}
```

| Status | Meaning                                      |
|--------|----------------------------------------------|
| 200    | success                                      |
| 404    | unknown account id                           |
| 422    | invalid query parameter, with `violations`   |

## Mock data

The `app:seed` command fills the database with reproducible mock events from both providers, their
inbound requests in the provider's own format and the resulting postings. Every seventh event stays `pending` without postings, the rest are
processed through the same `TransactionRecorder` the worker uses. Event ids are `seed_0001`,
`seed_0002` and so on; existing ids are skipped, so the command is safe to run repeatedly.

```bash
php bin/console app:seed                       # 30 events, keeps existing rows
php bin/console app:seed --count=100 --purge   # wipe events, inbound requests and transactions first
php bin/console app:seed --seed=7              # different random sequence
docker compose exec php php bin/console app:seed --purge   # or: make seed
```

## Project layout

```
docker/            Dockerfile, nginx config, postgres init script
scripts/           local DB bootstrap SQL
src/Command        console commands (mock data seeder)
src/Controller     HTTP layer, admin panel under Controller/Admin
src/Dto            request payloads with validation constraints, one per provider under Dto/Payload
src/Entity         Doctrine entities
src/Enum           event types, accounts, directions and posting rules
src/EventListener  exception to HTTP response mapping, notification log recorder
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
