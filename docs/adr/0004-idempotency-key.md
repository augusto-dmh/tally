# ADR-0004: Optional Idempotency-Key with stored terminal outcomes

- Status: Accepted
- Date: 2026-08-04

## Context

`POST /transfers` is not safe to retry by default: a client that times out after
the server committed can resubmit and debit twice. Challenge and demo curls also
need to keep working without extra headers. The service therefore needs an
opt-in deduplication contract that returns the first terminal outcome on replay,
rejects the same key used with a different body, and stays correct when two
identical keyed requests race.

## Decision

Accept an optional **`Idempotency-Key` HTTP header** on `POST /transfers`.

- **Absent or omitted key** — each request is independent (existing non-keyed
  behavior preserved).
- **Present key** — the server stores **terminal outcomes** (success and
  completed business rejections) in an `idempotency_keys` table with a unique
  primary key on the client key, a hash of the canonical request body, the HTTP
  status, and the exact JSON response body.
- **Replay** (same key, same body) returns the stored status and body without
  moving money again.
- **Conflict** (same key, different body) answers `422` with
  `idempotency_key_conflict`.
- **Insert-then-catch** — on the success path the outcome row is inserted inside
  the money transaction; a unique-key race means another worker won, that
  transaction rolls back, and the loser re-reads and returns the winner’s stored
  outcome.

Empty or whitespace-only keys are rejected as `422` `invalid_request`.

Alternatives considered:

- **Required idempotency key on every transfer.** Stronger for production clients,
  but breaks the challenge/demo curl contract and forces every caller to invent a
  key. Optional keeps the simple path and makes dedup explicit where retries
  matter.
- **Dedup only on success (transfer id as the record).** Business rejections
  (insufficient balance, unauthorized, …) would re-execute on replay — including
  a second trip to the authorizer. Storing terminal outcomes makes replay a pure
  read of what the client already saw.
- **Select-first / advisory locks for the key.** Insert-then-catch uses the unique
  constraint as the race authority and keeps the happy path to one round trip;
  the loser’s rollback also undoes any wallet writes from that attempt.

## Consequences

- Clients that retry with a stable key get at-most-once money movement for that
  key and a stable HTTP response.
- The idempotency store is a separate table from `transfers` so rejections without
  a transfer row can still be replayed.
- Keyed and unkeyed traffic share the same transfer use case; only the keyed path
  reads and writes the store.
- No TTL is defined yet — keys persist until an explicit retention decision.
- Reliable notification after commit is orthogonal (best-effort today); keyed
  replay still returns the committed transfer even if notify was dropped.
