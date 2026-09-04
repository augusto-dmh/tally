# ADR-0010: MySQL pool exhaustion from a held connection

- Status: Accepted
- Date: 2026-08-28

## Context

Hyperf workers share a finite MySQL connection pool. A coroutine that keeps a
borrowed connection open across slow work (an open transaction during
`Coroutine::sleep` or third-party HTTP) pins a pool slot for the full wait.
Enough concurrent pins exhaust the pool:
`Connection pool exhausted. Cannot establish new connection before wait_timeout.`

[ADR-0003](0003-wallet-row-locks.md) already keeps the authorizer outside the
money transaction. [ADR-0006](0006-transactional-outbox.md) already notifies
after the outbox claim returns, not inside that claim transaction. Those
short-txn / no-HTTP-on-a-held-connection rules were unmeasured: the Hyperf
skill described the anti-pattern without an in-repo incident, and a later
edit that put HTTP back inside a transaction would not fail CI.

## Decision

Treat MySQL pool exhaustion as a **held-connection incident**, proven by a
concurrent tiny-pool contrast, not by raising production `max_connections`.

**Proof.** A test-only Hyperf connection `pool_exhaustion_probe` copies
`databases.default` credentials via `ConfigInterface` and uses
`max_connections` 1 and `wait_timeout` 0.2. Two `Parallel` children: when A
holds an open transaction across `Swoole\Coroutine::sleep(0.5)`, B’s borrow
throws Hyperf’s pool-exhausted `RuntimeException`; when both sleep with no
connection and then run a short transaction, both succeed. Production
`config/autoload/databases.php` is not written (`max_connections` 10,
`wait_timeout` 3.0 stay).

**Production call order (unchanged).** `TransferFunds` authorizes before
`TransactionRunner::run`. `DrainOutbox` notifies after `Outbox::claimDue`
returns. Unit spies on the fakes fail if either HTTP call moves inside its
DB transaction. Public `POST /transfers` is unchanged. Pool exhaustion
stays unmapped infrastructure (`DomainExceptionHandler` does not treat
Hyperf’s `RuntimeException` as a business slug).

This ADR **is** the incident note; there is no separate `docs/incidents/`
tree. It does not supersede ADR-0003 or ADR-0006 — it measures them.

Not part of this decision: Guzzle / Redis pools, child-context copy /
`go()`, blocking I/O stalling the worker, `max_request`, k6 / load
harnesses, SAGA, production pool-knob retune, a production
`TransactionRunner` guard, and distinct public HTTP codes for infra outage
vs authorizer decline.

Alternatives considered:

- **Raise production `max_connections`.** Masks hold time instead of
  shortening it; rejected. The arithmetic is hold duration × concurrency,
  not “the cap was too small.”
- **Second named pool in production `databases.php`.** Ships unused pool
  knobs and mutates the production config file; rejected in favor of a
  test-only `ConfigInterface` probe.
- **Shrink the default pool at runtime for the feature suite.** Other
  integration tests share `default`; a fake that throws the error string is
  not Hyperf’s incident. Rejected.
- **Production `TransactionRunner` guard against slow work.** Would change
  application behavior; this record is proof-only. Rejected.

## Consequences

- CI fails if a later change holds the probe connection across slow work
  without exhausting, or if authorizer / notifier HTTP moves inside the
  money or claim transaction.
- Operators still see pool exhaustion as an unmapped 500 if it ever happens
  under load; there is no new public slug or status.
- Guzzle, Redis, k6, and a production guard remain later work; this ADR
  does not retune production pool knobs.
