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

namespace HyperfTest\Fake;

use Hyperf\CircuitBreaker\CircuitBreakerInterface;
use Hyperf\CircuitBreaker\State;
use RuntimeException;

/**
 * In-memory CircuitBreakerInterface for unit tests. Mirrors real CircuitBreaker
 * open/close/halfOpen resetting counters and timestamp; attempt() throws so
 * accidental coin-flip use fails loudly.
 */
final class FakeCircuitBreaker implements CircuitBreakerInterface
{
    public int $attemptCalls = 0;

    private State $state;

    private float $timestamp;

    private int $failCounter = 0;

    private int $successCounter = 0;

    public function __construct()
    {
        $this->state = new State();
        $this->timestamp = microtime(true);
    }

    public function state(): State
    {
        return $this->state;
    }

    public function attempt(): bool
    {
        ++$this->attemptCalls;
        throw new RuntimeException('breaker->attempt() coin-flip must not be used');
    }

    public function open(): void
    {
        $this->init();
        $this->state->open();
    }

    public function close(): void
    {
        $this->init();
        $this->state->close();
    }

    public function halfOpen(): void
    {
        $this->init();
        $this->state->halfOpen();
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->timestamp;
    }

    public function getFailCounter(): int
    {
        return $this->failCounter;
    }

    public function getSuccessCounter(): int
    {
        return $this->successCounter;
    }

    public function incrSuccessCounter(): int
    {
        return ++$this->successCounter;
    }

    public function incrFailCounter(): int
    {
        return ++$this->failCounter;
    }

    private function init(): void
    {
        $this->timestamp = microtime(true);
        $this->failCounter = 0;
        $this->successCounter = 0;
    }
}
