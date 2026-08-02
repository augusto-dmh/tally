---
impact: HIGH
tags: [pool, mysql, redis, guzzle, exhaustion, transactions]
---

# Connection pools

## Know the pool arithmetic before touching pool config

Every MySQL/Redis/HTTP client is pooled per worker. The keys (from
`config/autoload/databases.php`):

```php
'pool' => [
    'min_connections' => 1,
    'max_connections' => 10,    // hard cap PER WORKER
    'connect_timeout' => 10.0,
    'wait_timeout'    => 3.0,   // max seconds a coroutine waits to borrow
    'heartbeat'       => -1,
    'max_idle_time'   => 60.0,
],
```

`Connection pool exhausted. Cannot establish new connection before wait_timeout`
is arithmetic: more than `max_connections` coroutines held a connection longer
than `wait_timeout`. The fix is almost never "raise max_connections" — it is
shortening hold time (below) or bounding concurrency
([concurrency-primitives](concurrency-primitives.md)). Remember the DB-side cap:
`worker_num × max_connections` across all app instances must fit MySQL's
`max_connections`.

## Never hold a connection across slow external work

A connection is borrowed for the duration of a query — or, inside a transaction,
for the WHOLE transaction. An external HTTP call inside a transaction pins a
pooled connection (and any row locks) for the full latency of a third party.
This is the single fastest way to exhaust a pool under load.

**Incorrect:**

```php
Db::transaction(function () use ($transfer) {
    $this->wallets->debit($transfer);           // row locked, connection pinned
    $this->authorizer->authorize($transfer);    // 400ms external call while holding both
    $this->wallets->credit($transfer);
});
```

**Correct:**

```php
$this->authorizer->authorize($transfer);        // slow work OUTSIDE, first

Db::transaction(function () use ($transfer) {   // transaction = DB work only
    $this->wallets->debit($transfer);
    $this->wallets->credit($transfer);
});

$this->events->dispatchAfterCommit($transfer);  // side effects after commit
```

## Connections return at coroutine end — but don't lean on it

The framework releases a borrowed connection when the coroutine finishes. Inside
long-running coroutines (custom processes, consumers, crontab), that safety net
never fires between iterations: structure loops so each iteration borrows and
releases, and call `release()` explicitly when using low-level `SimplePool`.

## Outbound HTTP goes through the pooled Guzzle handler

Ad-hoc `new Client()` per call creates unpooled connections with no shared
limits. Use `hyperf/guzzle`'s `PoolHandler`-backed client via the container, with
explicit `timeout` and `connect_timeout` on every call — a third party without a
timeout owns your pool.
