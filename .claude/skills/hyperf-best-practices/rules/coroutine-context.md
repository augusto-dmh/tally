---
impact: CRITICAL
tags: [context, coroutine, request-state, tracing]
---

# Coroutine context

## Store per-request state in `Context`, never in properties or statics

Each request runs in its own coroutine; `Hyperf\Context\Context` is storage keyed
to the current coroutine and released automatically when it ends. Anything stored
on a service property or static is shared by every concurrent request in the
worker (see [stateful-services](stateful-services.md)).

**Incorrect:**

```php
class AuthService
{
    private ?User $currentUser = null; // shared by ALL concurrent requests

    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
}
```

**Correct:**

```php
use Hyperf\Context\Context;

class AuthService
{
    public function setUser(User $user): void
    {
        Context::set(User::class, $user);
    }

    public function user(): ?User
    {
        return Context::get(User::class);
    }
}
```

## Child coroutines start with an EMPTY context — copy what they need

`co()` / `go()` / `Coroutine::create()` do NOT inherit the parent's context. The
classic failure: a trace ID or authenticated user set in the request coroutine is
silently `null` inside a spawned child, detaching spans and breaking audit trails.

**Incorrect:**

```php
Context::set('trace_id', $traceId);

go(function () {
    // Context::get('trace_id') === null here — new coroutine, empty context
    $this->notifier->send(Context::get('trace_id'));
});
```

**Correct:**

```php
use Hyperf\Coroutine\Coroutine;

Context::set('trace_id', $traceId);

// fork() creates the child AND copies the named context keys into it
Coroutine::fork(function () {
    $this->notifier->send(Context::get('trace_id')); // present
}, ['trace_id']);

// equivalent manual form inside a plain go():
$parentId = Coroutine::id();
go(function () use ($parentId) {
    Context::copy($parentId, ['trace_id']);
    // ...
});
```

## Never carry the request object itself across coroutine boundaries

A `ServerRequestInterface` (and everything derived from it) belongs to the
request coroutine. After the response is sent, the parent context is destroyed —
a child holding a reference reads torn state. Extract the scalar values you need
(IDs, DTOs) and pass those.

**Incorrect:**

```php
go(fn () => $this->audit->log($this->request->getHeaderLine('x-user'))); // request may be gone
```

**Correct:**

```php
$userHeader = $this->request->getHeaderLine('x-user'); // extract first
go(fn () => $this->audit->log($userHeader));
```

## Use `Context::override()` for read-modify-write on a context value

Two awaits between a `get` and a `set` can interleave with other code in the same
coroutine chain; `override` makes the mutation atomic within the coroutine.

```php
Context::override('request_attributes', function (?array $attrs) use ($new) {
    return array_merge($attrs ?? [], $new);
});
```
