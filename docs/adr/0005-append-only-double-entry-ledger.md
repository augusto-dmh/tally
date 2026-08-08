# ADR-0005: Append-only double-entry ledger with projected balances

- Status: Accepted
- Date: 2026-08-07

## Context

After the transfer API shipped, wallet truth lived only in `wallets.balance_cents`
and a `transfers` row. That is enough to move money, but not enough to *explain*
balances: there is no append-only history of why a wallet holds what it holds, no
global conservation check, and no way to detect silent projection drift without
replaying every transfer by hand.

The service needs a journal that:

- explains every non-zero balance as the net of posted legs,
- stays consistent with the existing short money transaction and row locks
  ([ADR-0003](0003-wallet-row-locks.md)),
- leaves the public `POST /transfers` contract unchanged, and
- can prove continuously that credits equal debits and each wallet projection
  matches its journal net — without taking the API down when that check fails.

## Decision

Introduce an **append-only double-entry ledger** (`ledger_entries`) as the
explanatory journal for money movement, and keep `wallets.balance_cents` as a
**same-transaction projection** dual-written when legs are posted.

**Posting.** Each successful transfer posts a balanced debit/credit pair under
one UUID v4 `journal_id`. Legs for a transfer reference `transfer_id`; the payer
wallet is debited and the payee wallet credited for the same amount of cents.
Journal writes happen inside the money transaction after the transfer row is
persisted; a rolled-back or declined transfer leaves no legs.

**Opening.** Existing non-zero balances are explained by opening journals against
a system account kind (`system_opening`): credit the wallet, debit the system
account. Zero-balance wallets need no opening legs. Backfill is idempotent and
runs from migration and again after the demo seeder inserts wallets.

**Reconcile.** A read-only job compares (1) global Σ credits = Σ debits and
(2) each wallet’s `balance_cents` to the net of that wallet’s legs. Violations
are logged; the CLI `ledger:reconcile` exits non-zero. Reconcile **never**
writes ledger or wallet data and **never** blocks or fails a transfer. The same
command is scheduled in-process via `hyperf/crontab` (~3.2) every five minutes
(`CrontabDispatcherProcess` + command-type crontab with
`--disable-event-dispatcher`), so drift is detected continuously when the app is
up without inventing a second checker path.

The ledger is reached through a domain `Ledger` port (Approach A): application
services call the port; `DbLedger` persists; `TransferRepository` stays focused
on transfer rows.

Alternatives considered:

- **Chart of accounts / fee accounts.** Overweight for a two-party transfer with
  no fees this cycle; deferred until product needs more account kinds.
- **Balance snapshots for reconcile.** Useful at larger scale; full-table
  aggregates are acceptable at demo scale. Deferred.
- **HTTP statement / ledger query API.** Out of scope; the journal is an
  internal correctness upgrade, not a new public surface.
- **Block transfers when reconcile fails.** Would couple availability of money
  movement to a read-only checker and contradict the fail-the-job-only posture.
- **Drop the `transfers` table and derive history only from the ledger.** The
  transfer row remains the API’s identity and idempotency anchor; the journal
  explains balances alongside it.
- **Fold journal writes into `TransferRepository::add`.** Makes the transfer
  aggregate own ledger rules and awkward for opening/reconcile paths; rejected
  in favor of the `Ledger` port.
- **Rich mutable `Journal` domain aggregate.** Overweight for a fixed two-leg
  pair this cycle.

## Consequences

- Successful transfers always leave two balancing legs; projection and journal
  move in one transaction with the transfer row.
- Opening backfill makes seeded and migrated databases reconcile-clean before
  any new transfer.
- Operators run `bin/hyperf.php ledger:reconcile` (or rely on crontab while the
  app process is up); a non-zero exit is an ops signal, not an API outage.
- `hyperf/crontab` is a deliberate dependency for in-process scheduling of that
  same command — one implementation path for CLI and schedule.
- Public HTTP response shapes and status codes are unchanged; clients do not see
  the journal.
- CoA expansion, snapshots, and statement APIs remain open product decisions and
  would supersede parts of this ADR if taken later.
