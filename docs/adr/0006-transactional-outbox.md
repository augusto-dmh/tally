# ADR-0006: Transactional outbox for transfer notification

- Status: Accepted
- Date: 2026-08-08

## Context

After money commits, the transfer API used to call the external notifier on the
request path (post-commit best-effort). A notifier outage or crash between
commit and notify could lose the delivery forever: `201` meant money moved, not
that the recipient was (or would be) notified. That deferred reliability gap is
recorded in `AI_STRATEGY.md` (2026-08-04).

The short money transaction and row-lock posture from
[ADR-0003](0003-wallet-row-locks.md) still holds: external HTTP must not run on
a held DB connection. Closing the gap without putting notifier RTT back inside
the money txn requires durable intent storage and an asynchronous deliverer.

## Decision

Adopt a **pure transactional outbox** for transfer notification.

**Enqueue.** On successful transfer, insert one outbox row inside the same money
transaction as wallets, the transfer row, and ledger legs. Event type is
`transfer.completed`; the payload is frozen JSON sufficient to rebuild the
`Transfer` for notify. The request path does **not** call `TransferNotifier`.
Unique constraint on `(transfer_id, event_type)` prevents duplicate intents for
the same transfer and event.

**Table.** A generic `outbox` table (not notify-specific columns beyond what
any event needs): status, attempts, `available_at`, frozen payload, and related
fields for claim / retry / terminal states.

**Drain.** Application service `DrainOutbox` claims due rows, calls the
notifier, and marks `done` on success or schedules bounded backoff retries on
failure. After max attempts the row is marked in-place `status=dead` (retained,
not deleted). `OutboxRelayProcess` and CLI `outbox:drain` both call
`DrainOutbox` — one delivery path, two entry points.

**Semantics.** Delivery is **at-least-once**. `201` means money committed and
the notify intent is durably enqueued — **not** external notifier success, and
**not** exactly-once delivery to the third party. Terminal rows (`done` /
`dead`) are retained.

This supersedes ADR-0003’s **request-path post-commit notify placement** only.
The intent of a short money transaction and no HTTP on a held connection
remains.

Out of scope this cycle: authorizer breaker, provider fallback, SAGA, Kafka,
purge of terminal rows, and a manual replay CLI.

Alternatives considered:

- **Keep post-commit best-effort notify.** Leaves silent permanent loss under
  notifier outage or process crash; rejected now that the reliability gap is
  being closed.
- **Notify inside the money transaction.** Already rejected live (notifier
  `504` rolled back money and held the connection across HTTP).
- **Fold enqueue into `TransferRepository::add`.** Makes the transfer aggregate
  own event/outbox rules; awkward for drain/claim. Rejected in favor of an
  `Outbox` port called from `TransferFunds`.
- **`hyperf/async-queue` / Redis queue.** New dependency and a dual-write story
  with the MySQL money txn; rejected while a DB outbox suffices.

## Consequences

- Successful transfers always leave a durable `transfer.completed` intent in the
  same transaction as money movement; declined or rolled-back transfers leave
  none.
- Clients still get `201` when money and enqueue commit; external notify success
  is the relay’s job and may lag or retry.
- Operators drain via the in-process relay and/or `bin/hyperf.php outbox:drain`;
  `dead` rows are an ops signal in logs, not an API outage.
- At-least-once means the third party may see duplicate notify calls after
  crashes between success and `markDone`; callers must not treat `201` as
  exactly-once external delivery.
- Authorizer breaker, broker handoff, purge, and manual replay remain open
  decisions and would supersede parts of this ADR if taken later.
