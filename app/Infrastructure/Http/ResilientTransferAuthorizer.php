<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Infrastructure\Http;

use App\Domain\Transfer;
use Hyperf\CircuitBreaker\CircuitBreaker;
use Hyperf\CircuitBreaker\CircuitBreakerFactory;
use Hyperf\CircuitBreaker\CircuitBreakerInterface;
use Psr\Container\ContainerInterface;

/**
 * Per-provider AuthorizerAttempt: bounded retry on AuthorizerUnavailable and a
 * per-worker circuit breaker. Returns bool only for clear or explicit decline;
 * throws AuthorizerUnavailable when this provider cannot answer. Never uses
 * Attempt::attempt() coin-flip.
 */
final class ResilientTransferAuthorizer implements AuthorizerAttempt
{
    public function __construct(
        private readonly AuthorizerAttempt $inner,
        private readonly CircuitBreakerFactory $breakerFactory,
        private readonly ContainerInterface $container,
        private readonly string $breakerName = 'transfer.authorizer',
        private readonly int $maxAttempts = 3,
        private readonly int $backoffBaseMs = 50,
        private readonly int $backoffCapMs = 500,
        private readonly int $failCounterThreshold = 5,
        private readonly int $successCounterThreshold = 1,
        private readonly float $duration = 10.0,
    ) {
    }

    public function attempt(Transfer $transfer): bool
    {
        $breaker = $this->breaker();

        if ($breaker->state()->isOpen()) {
            if ($breaker->getDuration() < $this->duration) {
                throw new AuthorizerUnavailable();
            }
            $breaker->halfOpen();

            return $this->probe($breaker, $transfer);
        }

        // Another request on this worker is already probing (or left half-open):
        // fail closed without a second upstream call.
        if ($breaker->state()->isHalfOpen()) {
            throw new AuthorizerUnavailable();
        }

        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            try {
                $cleared = $this->inner->attempt($transfer);
            } catch (AuthorizerUnavailable $e) {
                if ($attempt < $this->maxAttempts) {
                    $this->backoff($attempt - 1);
                    continue;
                }

                if ($breaker->incrFailCounter() >= $this->failCounterThreshold) {
                    $breaker->open();
                }

                throw $e;
            }

            if ($cleared) {
                $this->recordSuccess($breaker);

                return true;
            }

            // Explicit decline: never bump failCounter; never retry.
            return false;
        }

        throw new AuthorizerUnavailable();
    }

    /**
     * Exactly one deterministic half-open probe. Unavailable reopens immediately
     * (ignore fail_counter). Decline counts as reachable success for the breaker.
     */
    private function probe(CircuitBreakerInterface $breaker, Transfer $transfer): bool
    {
        try {
            $cleared = $this->inner->attempt($transfer);
        } catch (AuthorizerUnavailable $e) {
            $breaker->open();

            throw $e;
        }

        if ($cleared) {
            $this->recordSuccess($breaker);

            return true;
        }

        $this->recordSuccess($breaker);

        return false;
    }

    private function breaker(): CircuitBreakerInterface
    {
        if ($this->breakerFactory->has($this->breakerName)) {
            /** @var CircuitBreakerInterface $existing */
            $existing = $this->breakerFactory->get($this->breakerName);

            return $existing;
        }

        return $this->breakerFactory->set(
            $this->breakerName,
            new CircuitBreaker($this->container, $this->breakerName),
        );
    }

    private function recordSuccess(CircuitBreakerInterface $breaker): void
    {
        if ($breaker->incrSuccessCounter() >= $this->successCounterThreshold) {
            $breaker->close();
        }
    }

    /**
     * Short exponential backoff with jitter. Zero base and cap → no sleep.
     */
    private function backoff(int $zeroBasedAttempt): void
    {
        if ($this->backoffBaseMs <= 0 && $this->backoffCapMs <= 0) {
            return;
        }

        $exp = $this->backoffBaseMs * (2 ** $zeroBasedAttempt);
        $capped = min($this->backoffCapMs, $exp);
        $ms = random_int(0, max(0, $capped));
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
