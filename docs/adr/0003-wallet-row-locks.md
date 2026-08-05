# ADR-0003: Lock wallet rows with SELECT … FOR UPDATE

- Status: Accepted
- Date: 2026-08-04

## Context

Concurrent transfers against one payer can overdraw when each request reads the
same balance, passes the sufficiency check, and writes independently. Under
InnoDB that is a lost update: two successful debits of a full balance leave the
payer at zero while both payees are credited — money created. The transfer flow
also used to hold a pooled DB connection across external HTTP (authorizer and
notifier), which couples money movement to third-party latency and exhausts the
pool under load.

The concurrency claim has to be proven: a race that fails without locking and
passes with it. The lock mechanism has to stay inside a short database
transaction so external calls never sit on a held connection or row lock.

## Decision

Use **pessimistic row locks**: `SELECT … FOR UPDATE` on the wallets that
participate in a transfer, taken **inside** the money transaction only, in
**ascending wallet id** order to avoid deadlocks between concurrent A→B and B→A
transfers.

The authorizer runs **before** that transaction (fail-closed: decline or outage
persists nothing). The notifier runs **after** commit (see the notify trade-off
recorded in `AI_STRATEGY.md`). The transaction itself only locks, re-checks
balances against locked rows, persists wallet balances and the transfer row, and
(when present) inserts the idempotency success outcome.

Alternatives considered:

- **Optimistic versioning (version column / compare-and-swap).** Correct under
  low contention, but a payments wallet under concurrent depleting transfers is
  exactly the high-contention case: losers retry, and the race test becomes a
  retry policy rather than a single serialized debit. Pessimistic locking makes
  “at most one full-balance debit succeeds” the natural outcome of InnoDB’s lock
  wait, not of application-level retry loops.
- **Redis (or other) distributed locks keyed by payer.** Adds a second failure
  domain and a consistency story between Redis and MySQL for a service whose
  system of record is already InnoDB. Rejected while MySQL row locks suffice.
- **Serialize transfers in application code (mutex / single worker).** Does not
  compose across multiple workers or future horizontal scale; the database
  already provides the right primitive.

## Consequences

- `WalletRepository` exposes an explicit `findByUserIdForUpdate` used only inside
  `TransactionRunner::run()`; unlocked reads remain for pre-checks and
  authorization.
- Concurrent depleting transfers cannot leave a negative payer balance or create
  money across payer and payees; the integration race test encodes that
  invariant and is expected to fail if `lockForUpdate()` is removed.
- Lock wait time is bounded by the short money transaction — not by authorizer
  or notifier RTT — which is the operational reason those calls stay outside.
- Deadlock risk between two-wallet transfers is mitigated by the ascending-id
  lock order; any remaining deadlock surfaces as a transaction failure the
  client can retry.
