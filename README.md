# tally

A payments service: users and merchants holding wallets, moving money over an
HTTP API. Built on Hyperf 3.2 / Swoole (PHP 8.3) with MySQL 8.

Medieval tally sticks were split wooden records of debt — each party kept half,
and neither could forge the record alone. A fitting name for a service whose
whole job is keeping an honest account of who paid whom.

## The domain

A simplified digital-payments model, treated with production seriousness:

- Regular users and merchants hold wallets.
- Users transfer money to users or merchants; merchants only receive.
- Transfers are validated against balance and cleared by an external
  authorizer before money moves; the recipient is notified.

## Status

Early — the service is just getting started on the official Hyperf skeleton.
Architecture decisions are recorded in [`docs/adr/`](docs/adr/) as they are
made; how AI is used in development is documented in
[`AI_STRATEGY.md`](AI_STRATEGY.md).

## Running

Requires Docker.

```bash
docker run --rm -v $(pwd):/data/project -w /data/project -p 9501:9501 \
  hyperf/hyperf:8.3-alpine-v3.19-swoole php bin/hyperf.php start
```

Then: `curl http://localhost:9501/`

Tests: `composer test` · Static analysis: `composer analyse` · Style: `composer cs-fix`

## Boundaries

- No frontend, no authentication, no registration flows — out of scope by design.
- MySQL is the system of record; Redis for cache and locks where warranted.
- The domain layer imports nothing from the framework.
