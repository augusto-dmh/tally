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
(declines and authorizer outages both fail closed), executed in one database
transaction, with the recipient notified. Money is integer cents end to end —
no floating point anywhere near an amount. Every business rule answers with a
precise status and a machine-readable error code.

## Status

Early — the transfer API is the first shipped slice. Architecture decisions are
recorded in [`docs/adr/`](docs/adr/) as they are made; how AI is used in
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
R$ 500,00), and merchant `3`, who can only receive.

Send a transfer:

```bash
curl -X POST http://localhost:9501/transfers \
  -H 'Content-Type: application/json' \
  -d '{"value": 100.50, "payer": 1, "payee": 2}'
```

```json
{"id":1,"payer":1,"payee":2,"value":"100.50","created_at":"2026-08-03T02:15:47+00:00"}
```

Two non-`201` answers are expected rather than broken. The public authorizer at
`util.devi.tools` alternates between clearing and declining, so a `403` on one
call and a `201` on the next is the upstream talking, not the service. Its
notifier is also occasionally down; notification runs inside the transfer's
database transaction, so a notifier failure surfaces as a `502` with the
transfer rolled back and both balances untouched.

Tests: `composer test` · Static analysis: `composer analyse` · Style: `composer cs-fix`
(run them in the container: `docker compose exec hyperf-skeleton composer test`)

## Boundaries

- No frontend, no authentication, no registration flows — out of scope by design.
- MySQL is the system of record; Redis for cache and locks where warranted.
- The domain layer imports nothing from the framework.
