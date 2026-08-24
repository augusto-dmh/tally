# ADR-0008: Authorizer provider-fallback chain

- Status: Accepted
- Date: 2026-08-16

## Context

`POST /transfers` still must clear an external authorizer before money moves.
[ADR-0007](0007-resilient-authorizer.md) hardened a single upstream with
timeouts, retry-on-unavailable, and a fail-closed per-worker circuit breaker,
and mapped every non-clearance to public `403` / `transfer_unauthorized`.
That leaf remains the right resilience shape. Binding it directly as
`TransferAuthorizer` made an open or exhausted primary indistinguishable from
an explicit decline: both returned `false`, so a second URI could not be tried
without either shopping a hard no or scraping breaker internals.

The port stays `TransferAuthorizer::authorize(): bool`. Authorization still
runs before the money txn ([ADR-0003](0003-wallet-row-locks.md)). Decline vs
unavailable remains a client-side distinction; clients still do not learn a
new public status for outage.

## Decision

Adopt a **config-ordered fallback chain** of ADR-0007 leaves. Production
`TransferAuthorizer` is `FallbackTransferAuthorizer`. Each usable provider is
a `ResilientTransferAuthorizer` around a `DeviToolsAuthorizer` with that
provider's base URI and a unique breaker name. The chain calls `attempt()`
sequentially in `providers` list order.

**Unavailable-only advance.** A leaf returns `bool` only for a parsed clear
(`true`) or explicit decline (`false`). Open breaker, leftover half-open,
exhausted retry, and unavailable probe throw `AuthorizerUnavailable` so the
chain can continue. The chain does not inspect breaker state: decline and
exhaust both used to look like `false`.

**Decline is terminal.** An explicit refusal stops the chain. Later providers
are not called. Shopping a hard no across vendors is out of scope.

**Per-provider resilience.** Each leaf keeps ADR-0007 timeouts, bounded retry
on unavailability only, and a fail-closed per-worker breaker. Breaker names
differ (`transfer.authorizer.primary` / `transfer.authorizer.fallback`) so
trips do not share counters.

**Config.** `config/autoload/authorizer.php` holds `defaults` plus an ordered
`providers` list. Array order is try-order. Entries with empty `base_uri` are
skipped. Both URIs speak the same DeviTools protocol. `AUTHORIZER_FALLBACK_URL`
defaults to empty, so the chain length is 1 until a secondary is configured.

**Public contract.** The port still returns `false` for decline and for
"every usable provider cannot answer." `TransferFunds` still maps that to
`403` / `transfer_unauthorized` with no money and no outbox row.

**Composition superseded.** ADR-0007's retry, breaker, and decline-vs-unavailable
split stay in force on each leaf. What this ADR supersedes is the single-client
binding: production no longer binds the resilient leaf (or the raw HTTP client)
as the port. Testing still overrides the port at config level
(`FakeTransferAuthorizer` when `APP_ENV=testing`).

Not part of this decision: SAGA or compensation, Redis-shared / cross-worker
breaker state, fallback-on-decline, a second vendor protocol, distinct HTTP
codes for outage, notifier/outbox changes, and live dual-upstream operations.

Alternatives considered:

- **Keep the resilient leaf as the port; inspect breaker state to decide
  whether to advance.** Decline and exhaust both look like `false`; the chain
  would couple to breaker internals. Rejected in favor of throw-on-cannot-answer.
- **Advance on decline as well as unavailable.** Treats an explicit refusal as
  something another vendor might overturn. Rejected: decline is terminal.
- **Outcome enum on the port.** Same topology, rewrites the domain contract
  for no new public behavior. Rejected; the port stays `bool`.

## Consequences

- An open or exhausted primary can still clear via a configured secondary;
  only a decline or total inability to answer fails closed as public `403`.
- Empty secondary URI keeps the single-upstream Compose story (length 1).
- Operators add a second DeviTools-compatible URI without changing the public
  API or the domain port.
- Breaker state remains in-process per worker and per provider; it does not
  coordinate across workers or across providers.
- Feature tests still fake the port; chain proofs live in unit tests with
  two HTTP handlers or fake leaves.
