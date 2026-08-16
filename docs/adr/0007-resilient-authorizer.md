# ADR-0007: Resilient authorizer (retry + per-worker circuit breaker)

- Status: Accepted
- Date: 2026-08-15

## Context

`POST /transfers` must clear an external authorizer before money moves. The
first shipped adapter treated explicit decline and transport/outage the same
way: one HTTP attempt, `false` → public `403` / `transfer_unauthorized`, fail
closed. That kept the public contract simple, but a brief upstream blip denied
transfers that a bounded retry would have cleared, and a sustained outage kept
every worker hammering the same unhealthy endpoint with no backoff or trip.

The short money transaction and no-HTTP-on-held-connection posture from
[ADR-0003](0003-wallet-row-locks.md) still holds: authorization runs before the
money txn, never inside it. The transactional outbox in
[ADR-0006](0006-transactional-outbox.md) already hardened notify delivery; this
decision hardens the authorizer edge only.

## Decision

Adopt a **config-driven resilient authorizer**: an HTTP client that distinguishes
decline from unavailability, wrapped by a decorator that owns bounded retry and
a fail-closed per-worker circuit breaker.

**Timeouts.** Connect and request timeouts (and base URI) live in
`config/autoload/authorizer.php`, overridable by env for URI/timeouts. Defaults
are deliberate (request `5.0`s, connect `2.0`s) so a hung upstream cannot stall
workers indefinitely.

**Decline vs unavailable.** The HTTP client (`DeviToolsAuthorizer`) performs a
single attempt: explicit refusal → `false`; clear → `true`; connect failure,
non-2xx, or unreadable body → `AuthorizerUnavailable`. It does not implement
the `TransferAuthorizer` port, so DI cannot skip the resilience layer by binding
the raw client.

**Bounded retry.** Production `TransferAuthorizer` is
`ResilientTransferAuthorizer`. It retries only on `AuthorizerUnavailable`, up to
`max_attempts` with short exponential backoff and jitter. Explicit decline is
never retried and never bumps the breaker's fail counter.

**Circuit breaker.** Drive `hyperf/circuit-breaker` programmatically via
`CircuitBreakerFactory` / `CircuitBreaker` (named `transfer.authorizer` by
default). No AOP / `#[CircuitBreaker]` on `TransferFunds` or the decorator's
port method — the package's annotation path and coin-flip half-open helpers are
a poor fit for decline-vs-unavailable. When open (and cool-down not elapsed),
`authorize()` returns `false` with no HTTP. State is in-process per worker.

**Public contract.** Any non-clearance still maps to `403` /
`transfer_unauthorized` with no money movement and no outbox enqueue. Decline,
exhausted outage, and open breaker share that answer; clients do not learn a
new status for unavailability.

**Composition.** Prod DI binds `TransferAuthorizer` → the resilient decorator
around the HTTP client. Testing still overrides the port with
`FakeTransferAuthorizer` via `test/dependencies.php` when `APP_ENV=testing`.

Not part of this decision: provider fallback, Redis-shared / cross-worker
breaker state, SAGA or compensation paths, and notifier hardening beyond
ADR-0006.

Alternatives considered:

- **Keep single-attempt fail-closed.** Leaves transient outages as permanent
  denials for that request and keeps hammering a dead upstream; rejected now
  that authorizer resilience is in scope.
- **`#[CircuitBreaker]` AOP on the use case or decorator.** Heavier test
  surface; annotation timeout handlers do not distinguish decline from
  unavailable. Rejected in favor of programmatic factory use inside the
  decorator.
- **Fat HTTP client owning retry + breaker.** Weaker separation; accidental
  direct bind would skip resilience. Rejected in favor of decorator composition.

## Consequences

- Transient authorizer outages can clear within the attempt bound; sustained
  outages trip the breaker and fail closed without further HTTP until cool-down.
- Explicit declines remain one attempt, breaker-neutral, and public `403`.
- Operators tune URI, timeouts, retry, and breaker knobs from config without
  changing the public API.
- Breaker state does not coordinate across workers; under multi-worker load
  each process trips independently until a shared-state decision supersedes
  this ADR.
- Provider fallback and distinct HTTP codes for outage remain open product
  choices and would supersede parts of this ADR if taken later.
