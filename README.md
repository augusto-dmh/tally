# tally

A payments service: users and merchants holding wallets, moving money through
an HTTP transfer API. Built on Hyperf 3.2 / Swoole (PHP 8.3) with MySQL 8.

Medieval tally sticks were split wooden records of debt — each party kept half,
and neither could forge the record alone. A fitting name for a service whose
whole job is keeping an honest account of who paid whom.

## What it does

tally implements a simplified digital-payments domain — regular users and
merchants holding wallets; users transfer money to users or merchants; merchants
only receive — and treats it with production seriousness.

`POST /transfers` executes a wallet-to-wallet transfer: validated against
balance and user type, cleared by an external authorizer before money moves
(declines and authorizer outages both fail closed), executed under row locks in
a short database transaction that also enqueues a durable transfer-completed
outbox intent. A background relay (and optional `outbox:drain` CLI) delivers
the recipient notification asynchronously — at-least-once, not exactly-once.
Money is integer cents end to end — no floating point anywhere near an amount.
Every business rule answers with a precise status and a machine-readable error
code.

Clients may send an optional `Idempotency-Key` header. When present, the first
terminal outcome for that key is stored and returned on replay; the same key
with a different body is rejected. Without the header, each request is
independent.

## Status

Early — the transfer API is the first shipped slice, now backed by an append-only
double-entry ledger that explains wallet balances
([ADR-0005](docs/adr/0005-append-only-double-entry-ledger.md)) and a
transactional outbox for transfer notification
([ADR-0006](docs/adr/0006-transactional-outbox.md)). Architecture decisions are
recorded in [`docs/adr/`](docs/adr/) as they are made (including
[ADR-0003](docs/adr/0003-wallet-row-locks.md) row locks and
[ADR-0004](docs/adr/0004-idempotency-key.md) idempotency); how AI is used in
development is documented in [`AI_STRATEGY.md`](AI_STRATEGY.md).

## Running

Requires Docker. Start the stack (app + MySQL), then create the schema and the
demo accounts:

```bash
docker compose up -d --wait
docker compose exec hyperf-skeleton php bin/hyperf.php migrate --force
docker compose exec hyperf-skeleton php bin/hyperf.php db:seed
```

The seed creates three accounts: user `1` (Alice, R$ 1000,00), user `2` (Bruno,
R$ 500,00), and merchant `3`, who can only receive. Non-zero wallets get opening
ledger journals so balances are journal-explained from the start.

Reconcile the ledger against wallet projections (read-only; exits `0` when clean,
`1` on drift). While the app is up, `hyperf/crontab` runs the same command every
five minutes:

```bash
docker compose exec hyperf-skeleton php bin/hyperf.php ledger:reconcile
```

Send a transfer:

```bash
curl -X POST http://localhost:9501/transfers \
  -H 'Content-Type: application/json' \
  -d '{"value": 100.50, "payer": 1, "payee": 2}'
```

```json
{"id":1,"payer":1,"payee":2,"value":"100.50","created_at":"2026-08-03T02:15:47+00:00"}
```

Optional idempotent retry:

```bash
curl -X POST http://localhost:9501/transfers \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-1' \
  -d '{"value": 100.50, "payer": 1, "payee": 2}'
```

Two non-`201` answers are expected rather than broken. The public authorizer at
`util.devi.tools` alternates between clearing and declining, so a `403` on one
call and a `201` on the next is the upstream talking, not the service. Its
notifier is also occasionally down; that does not block `201`. A successful
transfer commits money and a durable outbox row in one transaction; delivery
is the job of `OutboxRelayProcess` (while the app is up) or a one-shot
`outbox:drain`. Failures retry with backoff up to a bound, then the row stays
`dead` for operators — `201` means money + enqueue, not external notifier
success.

Verification gates (Compose stack must be up):

- `make gate-quick` — unit suite + `composer analyse`
- `make gate-integration` — feature + integration suites (needs MySQL)
- `make gate-full` — full suite + analyse

Composer scripts remain the underlying contract: `composer test`,
`composer analyse`, `composer cs-fix`
(run them in the container: `docker compose exec hyperf-skeleton composer test`).

## Boundaries

- No frontend, no authentication, no registration flows — out of scope by design.
- MySQL is the system of record; Redis for cache and locks where warranted.
- The domain layer imports nothing from the framework.
