---
name: hyperf-best-practices
description: >
  Apply this skill whenever writing, reviewing, or refactoring PHP code in this
  Hyperf/Swoole application. This includes creating or modifying controllers,
  middleware, services, aspects, processes, crontab tasks, and tests; injecting
  dependencies or adding constructor/property state to any container-managed
  class; storing or reading per-request data (auth principal, trace/request IDs);
  spawning coroutines with co()/go()/Coroutine::create/fork or using
  Parallel/WaitGroup/Concurrent; configuring or debugging MySQL/Redis/HTTP
  connection pools; calling external services from request handlers; anything
  touching config/autoload/server.php, worker lifecycle, deploys, or
  runtime/container proxies; and writing tests with hyperf/testing. Also use for
  code review of any Hyperf code. Do NOT use for domain-layer code that imports
  nothing from the framework (plain PHP rules apply), nor for Laravel projects —
  Hyperf's Eloquent-like ORM and DI look familiar but run under a long-lived
  coroutine runtime with different rules.
license: MIT
metadata:
  author: augusto-dmh
---

# Hyperf Best Practices

## The one mental model

This application is NOT PHP-FPM. Workers are long-lived processes: the code is
loaded once, the DI container and every `@Inject`ed service live for the worker's
whole lifetime, and many requests run **concurrently inside one worker** as
coroutines. Every rule below is a consequence of that. When in doubt, ask: "what
happens when two requests run this line at the same time in the same process, and
what happens on request #10,000?"

## Consistency first

Follow the codebase's existing conventions (layering, naming, directory layout)
even where a rule suggests an alternative pattern. Rules govern runtime safety;
the codebase governs style.

## Rules index

| Rule | Impact | One-line summary |
|---|---|---|
| [coroutine-context](rules/coroutine-context.md) | CRITICAL | Request state lives in `Context`, and it does not follow you into child coroutines |
| [stateful-services](rules/stateful-services.md) | CRITICAL | Every container service is a shared singleton — no mutable request state in properties |
| [connection-pools](rules/connection-pools.md) | HIGH | Pools are finite; never hold a connection across slow external work |
| [blocking-io](rules/blocking-io.md) | HIGH | One truly blocking call stalls every coroutine in the worker |
| [worker-lifecycle](rules/worker-lifecycle.md) | HIGH | Code and proxies load once; deploys, dev loops, and memory follow from that |
| [concurrency-primitives](rules/concurrency-primitives.md) | MEDIUM | Bounded `Parallel`/`Concurrent` fan-out; exceptions and context need explicit handling |
| [testing](rules/testing.md) | MEDIUM | In-process HTTP tests via `hyperf/testing`; concurrency claims get concurrency tests |

## Quick reference

1. **[Coroutine context](rules/coroutine-context.md)**
   - Per-request data goes in `Hyperf\Context\Context`, keyed by class/interface name
   - `co()`/`go()` children start with an EMPTY context — trace IDs and auth vanish
   - Use `Coroutine::fork($fn, $keys)` (or `Context::copy()`) to carry keys into children
   - Never cache a `ServerRequestInterface` or the authenticated user in a property

2. **[Stateful services](rules/stateful-services.md)**
   - DI resolves singletons: one instance serves all concurrent requests
   - Mutable per-request properties on services = cross-user data leaks
   - Immutable config/dependencies in properties: fine; accumulating state: bug
   - Reset-at-request-start is not a fix — coroutines interleave

3. **[Connection pools](rules/connection-pools.md)**
   - Keys: `min_connections`, `max_connections`, `connect_timeout`, `wait_timeout`, `heartbeat`, `max_idle_time`
   - "Pool exhausted, cannot establish new connection before wait_timeout" = arithmetic, not mystery
   - Never call an external HTTP service while holding a transaction/connection
   - Size pools against worker_num × expected concurrent coroutines

4. **[Blocking I/O](rules/blocking-io.md)**
   - Runtime hooks default to `SWOOLE_HOOK_ALL`: PDO, curl, sleep, file I/O are coroutine-aware
   - Residual dangers: CPU-bound loops, un-hooked C extensions, huge synchronous payloads
   - Outbound HTTP: Guzzle with the hyperf/guzzle pool handler
   - Symptom of a blocking call: p99 collapses on unrelated endpoints while CPU idles

5. **[Worker lifecycle](rules/worker-lifecycle.md)**
   - New code does nothing until workers restart; deploys must reload gracefully
   - Dev loop: `runtime/container` proxies go stale — clear them or use `server:watch`
   - Production: pre-generate proxies at image build (`di:init-proxy`)
   - Watch per-worker RSS over time; treat `max_request` as a seatbelt, not a fix

6. **[Concurrency primitives](rules/concurrency-primitives.md)**
   - `new Parallel($limit)` — always pass a concurrency limit for unbounded input
   - `wait(true)` throws a combined exception; inspect per-key results on partial failure
   - Serialize per-key work with a `Concurrent` or channel, not with locks
   - `defer()` for cleanup that must run when the coroutine ends

7. **[Testing](rules/testing.md)**
   - `Hyperf\Testing\TestCase` + `$this->get('/')` runs requests in-process — no server needed
   - `composer test` (co-phpunit) runs tests inside a coroutine container
   - A concurrency guarantee without a concurrent test is an assumption
   - Reset Context/container state between tests that touch it
