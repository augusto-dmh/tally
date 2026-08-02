---
impact: MEDIUM
tags: [parallel, waitgroup, concurrent, channel, fanout]
---

# Concurrency primitives

## Bound every fan-out

`Hyperf\Coroutine\Parallel` takes a concurrency limit in its constructor;
`Hyperf\Coroutine\Concurrent` rate-limits a stream of spawns. Fanning out one
coroutine per item over unbounded input floods the scheduler and the pools
behind it ([connection-pools](connection-pools.md)).

**Incorrect:**

```php
$parallel = new Parallel();                    // unbounded
foreach ($deliveries as $d) {
    $parallel->add(fn () => $this->send($d));  // 10k coroutines, 10 pool slots
}
$results = $parallel->wait();
```

**Correct:**

```php
$parallel = new Parallel(16);                  // explicit ceiling
foreach ($deliveries as $i => $d) {
    $parallel->add(fn () => $this->send($d), $i); // key results for attribution
}
$results = $parallel->wait();
```

## Handle partial failure explicitly

`$parallel->wait(true)` (default) throws a combined `ParallelExecutionException`
if ANY task threw — successful results ride along inside the exception's
results. For fan-outs where partial success is meaningful (deliveries,
notifications), call `wait(false)` and inspect per-key results, or catch and
reconcile. Deciding this per call site is the design work; defaulting blindly is
the bug.

## Context does not follow the fan-out

Every `Parallel`/`go()` child starts with an empty context — trace IDs and auth
must be copied in via `Coroutine::fork()` or `Context::copy()`
([coroutine-context](coroutine-context.md)). A fan-out whose spans detach from
the parent trace hit exactly this.

## Serialize per-key work with a channel-fed loop, not locks

To make operations on one key (one wallet, one endpoint) sequential while keys
stay concurrent, route work through a per-key `Concurrent(1)` or a channel
consumed by one coroutine. Coroutine-level mutexes reintroduce lock ordering and
starvation reasoning that channels make structural — and no in-process primitive
protects an invariant across multiple workers or instances; cross-process
correctness belongs to the database.

## `defer()` for must-run cleanup

`Hyperf\Coroutine\defer()` runs when the current coroutine ends, success or
throw — the home for releasing manual resources acquired in fan-out bodies.
