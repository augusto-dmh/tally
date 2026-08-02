---
impact: HIGH
tags: [lifecycle, deploy, proxies, memory, reload, devloop]
---

# Worker lifecycle

## Code loads once — a deploy is a worker restart, not a file copy

Workers hold the application in memory; changed files on disk do nothing until
workers restart. Development uses `php bin/hyperf.php server:watch` (or restarts
after edits); production deploys must gracefully reload/replace workers so
in-flight requests — and in-flight coroutines they spawned — drain before exit.
"It still runs the old code" after a deploy is this rule, not a caching mystery.

## Stale DI proxies are the classic dev-loop trap

Hyperf compiles AOP/DI proxy classes into `runtime/container/proxy/` and reuses
them even when the source changed. Symptoms: an edit to a class with aspects or
annotated injection appears to have no effect, or a BadMethodCall points at a
proxy. Fix in dev:

```bash
rm -rf runtime/container   # then restart the server
```

In production images, generate proxies at build time so cold start is
deterministic and the runtime directory is never written in prod:

```dockerfile
RUN php bin/hyperf.php di:init-proxy
```

## Memory: measure per-worker RSS over time; `max_request` is a seatbelt

Nothing frees memory between requests — a slow leak (an accumulating static, a
listener collecting payloads) shows as linear per-worker RSS growth over hours.
Discipline:

- Watch RSS per worker under sustained load before claiming there is no leak.
- Find and fix the accumulator; then still set Swoole's `max_request` so a
  worker recycles after N requests — a seatbelt for the leak you haven't
  found yet, not a substitute for finding it.

**Incorrect:** "memory is stable in a 30-second test, ship it."
**Correct:** a sustained-load run with RSS sampled per worker, graphed, and flat.

## One worker per container is the cloud-native shape

Prefer `SWOOLE_BASE` mode with a single worker per container and scale
horizontally — process supervision belongs to the orchestrator. Liveness and
readiness endpoints, JSON logs to stdout, and SIGTERM-honoring graceful shutdown
(clear timers and long-lived coroutines on exit) complete the contract.
