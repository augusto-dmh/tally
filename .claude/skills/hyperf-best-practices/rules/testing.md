---
impact: MEDIUM
tags: [testing, co-phpunit, http, concurrency-tests]
---

# Testing

## HTTP behavior is tested in-process — no running server

`Hyperf\Testing\TestCase` boots the container and routes requests through the
real middleware/router stack in-process:

```php
final class TransferTest extends TestCase
{
    public function testRejectsInsufficientBalance(): void
    {
        $this->post('/transfer', ['value' => 100.0, 'payer' => 4, 'payee' => 15])
            ->assertStatus(422);
    }
}
```

`composer test` runs `co-phpunit`, which wraps each test in a coroutine
container — coroutine-dependent code (pools, context, hooks) behaves as in
production, unlike bare `phpunit`.

## A concurrency claim requires a concurrency test

"Safe under concurrent submission" is proven by a test that runs the operations
concurrently and asserts the invariant — and that FAILS when the protection is
removed. A sequential test of concurrent code tests nothing.

```php
public function testConcurrentTransfersCannotOverdraw(): void
{
    $wallet = $this->seedWallet(balance: 100_00);

    $parallel = new Parallel();
    for ($i = 0; $i < 10; $i++) {
        $parallel->add(fn () => $this->post('/transfer', [
            'value' => 20.0, 'payer' => $wallet->id, 'payee' => $this->payee->id,
        ]));
    }
    $parallel->wait(false);

    self::assertGreaterThanOrEqual(0, $this->balanceOf($wallet));
    self::assertSame(100_00, $this->ledgerSumFor($wallet)); // invariant, not vibes
}
```

Validate the test by reverting the protection (drop the lock) and watching it
fail — a concurrency test that can't fail is theater.

## Isolate worker-global state between tests

The container, `Context`, and any static caches persist across tests in one
process. Tests that set context keys or swap container definitions must clean up
(destroy the keys, restore definitions) or later tests inherit ghosts. Flaky
order-dependent tests in this runtime are almost always this rule.

## External dependencies are faked at the interface boundary

The authorizer/notifier clients are injected via interfaces; tests bind fakes in
the container rather than HTTP-mocking. Failure modes (timeout, 5xx, hang) are
first-class fake behaviors — resilience code paths get exercised, not assumed.
