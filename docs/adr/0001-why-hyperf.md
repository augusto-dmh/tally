# ADR-0001: Build on Hyperf/Swoole rather than Laravel or Symfony

- Status: Accepted
- Date: 2026-08-02

## Context

tally is a payments service: a wallet backed by a double-entry ledger exposing an
idempotent transfer API. Its interesting problems are concurrency (simultaneous
transfers against one wallet), partial failure (an external authorizer and a
notifier that are allowed to be down), and runtime behavior under load
(connection pools, long-lived process memory, blocking I/O).

A PHP framework had to be chosen before the first line of code. The realistic
candidates:

- **Laravel (with Octane)** — the dominant PHP framework and prior experience.
  Octane keeps the app booted between requests, but concurrency is process-based:
  one request per worker at a time, no coroutines inside a worker. Sequential
  outbound calls stay sequential. The FPM-era mental model (state dies with the
  request) mostly survives, which also means the runtime teaches little.
- **Symfony** — mature, explicit, excellent for layered architecture, but the same
  synchronous process model applies.
- **Hyperf on Swoole** — built *for* a coroutine runtime rather than adapted to
  one: every I/O component ships coroutine-aware and pooled, request state lives
  in coroutine context instead of globals, and resilience primitives this domain
  needs (circuit breaker, rate limiter, retry, async queue, crontab, custom
  processes) are first-party components rather than third-party packages.

## Decision

Build on **Hyperf 3.2 / Swoole**, PHP 8.3.

The deciding argument is alignment between the framework's constraints and the
service's actual problems. In a long-lived coroutine runtime, the failure modes
that matter in payments — shared state under concurrency, a held connection
starving a pool, one blocking call degrading unrelated requests — are not
theoretical topics but things this codebase must handle explicitly and can
therefore demonstrate and measure. A framework that hides those concerns would
produce a correct-looking service with nothing observable to say about its own
behavior under load.

Costs accepted with this decision:

- **Statefulness discipline.** Nothing request-scoped may live in statics,
  singletons, or container-shared mutables; coroutine context is the only safe
  home. Violations are data-leak bugs, not style issues.
- **Operational complexity.** Deploys must drain in-flight work (workers hold the
  app in memory); AOP proxies must be pre-generated at image build; worker memory
  must be watched over time.
- **Ecosystem depth.** Hyperf's ecosystem is thinner than Laravel's; some
  conveniences will be hand-built, which is acceptable — and occasionally the
  point — for a service this size.

## Consequences

- The domain layer stays framework-free (interfaces at the edges), so the choice
  remains swappable in principle and testable without a running server.
- Concurrency, pooling, and lifecycle behavior get dedicated verification as the
  project evolves — claims about the runtime are to be demonstrated, not assumed.
- MySQL 8/InnoDB is the system of record; Redis serves cache/locks where
  warranted. Both installed from day 0; every further component
  (queue, messaging, tracing) enters only when the work needs it, with its own
  decision record.
