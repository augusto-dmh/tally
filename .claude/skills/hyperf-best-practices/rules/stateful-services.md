---
impact: CRITICAL
tags: [di, singleton, state, concurrency, data-leak]
---

# Stateful services

## Treat every container-managed class as a shared singleton

Hyperf's DI container resolves a class once per worker and hands the same
instance to every request — concurrently. Controllers, middleware, services,
listeners, aspects: all singletons. In a payments context, request state on a
service property is not a style issue — it is user A seeing user B's balance.

**Incorrect:**

```php
class TransferService
{
    private string $payerId; // one slot, N concurrent requests

    public function handle(TransferRequest $request): Receipt
    {
        $this->payerId = $request->payerId();      // request A writes...
        $this->authorize();                        // ...suspends on I/O...
        return $this->execute($this->payerId);     // ...reads request B's value
    }

    private function authorize(): void { /* awaits external call */ }
}
```

**Correct:**

```php
class TransferService
{
    // properties hold only immutable collaborators
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly WalletRepository $wallets,
    ) {}

    public function handle(TransferRequest $request): Receipt
    {
        // per-request data flows through parameters and locals only
        $this->authorizer->authorize($request);
        return $this->execute($request->payerId());
    }
}
```

## What may live in a property

- Injected collaborators (repositories, clients, config objects) — immutable
  after construction.
- Lazily computed **pure** values (parsed config, compiled patterns) that are
  identical for every request, set once, never rewritten.

What may not: anything derived from a request, an accumulator, a "current"
anything. If a property name could start with `current` or `last`, it belongs in
`Context` ([coroutine-context](coroutine-context.md)) or a method parameter.

## Resetting state at request start is not a fix

Coroutines interleave: request B's handler can run between any two awaits of
request A's. A `reset()` at the top of `handle()` narrows the race window; it
does not close it. The only safe shapes are stateless services and
coroutine-scoped context.

## Statics are worker-global — including framework-invisible ones

`static` properties, `static function` locals, and memoizing helpers
(`once()`-style caches keyed on nothing) live until the worker dies and are
shared across all requests AND all coroutines. Grep-level red flags: `static $`,
`self::$`, hand-rolled `$cache[]=` in long-lived classes.
