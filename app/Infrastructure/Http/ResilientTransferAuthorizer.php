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

use App\Domain\Port\TransferAuthorizer;
use App\Domain\Transfer;
use Hyperf\CircuitBreaker\CircuitBreaker;
use Hyperf\CircuitBreaker\CircuitBreakerFactory;
use Hyperf\CircuitBreaker\CircuitBreakerInterface;
use Psr\Container\ContainerInterface;

/**
 * TransferAuthorizer decorator: bounded retry on AuthorizerUnavailable and a
 * per-worker circuit breaker. Never uses Attempt::attempt() coin-flip.
 */
final class ResilientTransferAuthorizer implements TransferAuthorizer
{
    public function __construct(
        private readonly DeviToolsAuthorizer $inner,
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

    public function authorize(Transfer $transfer): bool
    {
        $breaker = $this->breaker();

        if ($breaker->state()->isOpen()) {
            if ($breaker->getDuration() < $this->duration) {
                return false;
            }
            $breaker->halfOpen();
        }

        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            try {
                $cleared = $this->inner->attempt($transfer);
            } catch (AuthorizerUnavailable) {
                if ($attempt < $this->maxAttempts) {
                    $this->backoff($attempt - 1);
                    continue;
                }

                if ($breaker->incrFailCounter() >= $this->failCounterThreshold) {
                    $breaker->open();
                }

                return false;
            }

            if ($cleared) {
                $this->recordSuccess($breaker);

                return true;
            }

            // Explicit decline: never bump failCounter. Half-open decline means
            // upstream is reachable → breaker success, still deny the transfer.
            if ($breaker->state()->isHalfOpen()) {
                $this->recordSuccess($breaker);
            }

            return false;
        }

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
