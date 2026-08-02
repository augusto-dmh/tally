---
impact: HIGH
tags: [swoole, hooks, blocking, cpu-bound, latency]
---

# Blocking I/O

## Understand what the runtime hooks already cover

`bin/hyperf.php` sets `SWOOLE_HOOK_FLAGS` to `SWOOLE_HOOK_ALL` (via
`Hyperf\Engine\DefaultOption::hookFlags()`): Swoole monkey-patches PHP's native
I/O — PDO, phpredis, curl, streams, file functions, `sleep()` — so they suspend
the coroutine instead of blocking the process. Most "obvious" blocking calls are
therefore safe here. Do not "fix" hooked calls that aren't broken; know the
residual dangers instead.

## The real blockers: CPU-bound work and un-hooked extensions

While a coroutine computes, no other coroutine in that worker runs — hooks help
I/O, not CPU. The symptom is distinctive: p99 collapses across UNRELATED
endpoints in the same worker while CPU sits busy (CPU-bound) or idle (un-hooked
blocking call). Suspects: heavy serialization/crypto/report generation in a
request handler; C extensions doing their own I/O outside Swoole's hooks (some
DB drivers, image/PDF libraries, LDAP).

**Incorrect:**

```php
public function statement(int $walletId): ResponseInterface
{
    $pdf = $this->renderer->render($this->entries($walletId)); // 2s CPU in-request
    return $this->response->download($pdf);
}
```

**Correct:**

```php
public function statement(int $walletId): ResponseInterface
{
    // CPU-heavy work leaves the request path: async queue / task worker /
    // dedicated process — the request returns a pointer to the result.
    $jobId = $this->statements->enqueue($walletId);
    return $this->response->json(['statement_job' => $jobId])->withStatus(202);
}
```

## Outbound HTTP: Guzzle via the container, with timeouts, always

Guzzle over the hooked curl (or the `hyperf/guzzle` pool handler) is the
sanctioned path ([connection-pools](connection-pools.md)). Every call sets
`timeout` and `connect_timeout`; an unbounded wait on a third party stalls the
borrowing coroutine and, under load, everything queued behind its pool slot.

## Verify with load, not by reading

A suspected blocking call is confirmed by measurement: hit an unrelated fast
endpoint under concurrency while triggering the suspect path — if the fast
endpoint's latency degrades, the worker is being stalled. `Coroutine::stats()`
and per-worker RSS/CPU make the case; guessing does not.
