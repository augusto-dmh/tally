# ADR-0009: Request-scoped in-flight parties

- Status: Accepted
- Date: 2026-08-24

## Context

Hyperf's DI container resolves a class once per worker and hands the same
instance to every concurrent request. Request-derived payer and payee user
ids stored on a container-managed property (or a static) are therefore one
slot shared by every coroutine in the worker: under interleaving, last
writer wins and a later `get` can return another request's parties.

`POST /transfers` already identifies payer and payee from the JSON body and
passes them as arguments into `TransferFunds`. That money path does not
need a shared slot. What needed a recorded place is the in-flight pair
itself — request-scoped, isolated across concurrent coroutines, without
changing the public transfer contract.

## Decision

Store request-derived in-flight **payer and payee user ids** in coroutine
`Context` behind the domain port `InFlightTransfer`, not on container
service properties or statics.

**Production adapter.** Production binds `InFlightTransfer` to
`ContextInFlightTransfer`, which `Context::set` / `Context::get`s an
`InFlightTransferParties` value keyed by `InFlightTransferParties::class`.

**HTTP edge write.** After both `payer` and `payee` parse as integers, the
transfer controller writes the pair. Unusable JSON (non-object body, or a
non-integer party) never writes. A later `get` on that coroutine therefore
sees `null` when the request never produced a usable pair.

**Money path stays argument-driven.** `TransferFunds` does not read the
slot. Amounts, parties for debit/credit, and idempotency continue to travel
on `TransferFundsInput`. The slot is not a second source of truth for
money movement.

**Isolation proof.** A concurrent unit test sets two distinct pairs on two
coroutines, yields, then `get`s: `ContextInFlightTransfer` keeps each
coroutine's own pair; the same assertions fail on `PropertyInFlightTransfer`
(a property-backed store used only as the failing contrast). That test is
the proof; this ADR does not add a separate incidents tree.

**Public contract.** `POST /transfers` request and response shapes are
unchanged. No wallet-read API, auth, or request principal is introduced.

Not part of this decision: child-context copy / `go()` inheritance,
OpenTelemetry / metrics, k6 / load testing, SAGA, Redis-shared breaker, a
wallet-read API / auth / request principal, amount or idempotency in the
slot, and a separate `docs/incidents/` tree (this ADR is the incident note).

Alternatives considered:

- **Property (or static) store on a container service.** One `$current`
  slot, last writer wins under concurrency. Rejected; that is the shape the
  concurrent unit test shows failing isolation.
- **Have `TransferFunds` read parties from the slot.** Couples money
  movement to a side channel and would make a missed write a money bug.
  Rejected; the money path stays arguments.
- **Put amount or idempotency in the slot.** Widens the slot past parties
  without a reader that needs them. Rejected.
- **A separate `docs/incidents/` tree.** Splits the record of why Context
  won. Rejected; this ADR is that note.

## Consequences

- Concurrent requests on one worker cannot observe each other's in-flight
  payer/payee through the production adapter.
- A property-shaped adapter remains in tests as the failing contrast, not
  as a production binding.
- Because `TransferFunds` ignores the slot, a missed or overwritten write
  cannot silently move money for the wrong parties via Context; isolation
  bugs in the slot stay diagnostic, not ledger bugs.
- Child coroutines spawned with `go()` do not inherit a specified copy of
  the slot; that copy behavior is left unset.
- Operators and future work still have no wallet-read GET, auth principal,
  or observability hooks from this decision.
