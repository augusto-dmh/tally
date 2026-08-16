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

namespace HyperfTest\Unit\Infrastructure;

use App\Domain\Money;
use App\Domain\Transfer;
use App\Infrastructure\Http\DeviToolsAuthorizer;
use App\Infrastructure\Http\ResilientTransferAuthorizer;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\CircuitBreaker\CircuitBreakerFactory;
use Hyperf\CircuitBreaker\CircuitBreakerInterface;
use Hyperf\CircuitBreaker\State;
use HyperfTest\Fake\RecordingClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * AUTHZ-01..06 for ResilientTransferAuthorizer.
 *
 * @internal
 * @coversNothing
 */
final class ResilientTransferAuthorizerTest extends TestCase
{
    private const BREAKER = 'transfer.authorizer';

    public function testAuthz01DeclineIsOneAttemptWithNoFailBump(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":false}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $authorizer = $this->resilient($handler, $breaker);

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertSame(0, $handler->count());
        $this->assertSame(0, $breaker->getFailCounter());
        $this->assertTrue($breaker->state()->isClose());
    }

    public function testAuthz01EdgeUnavailableThenDeclineStopsWithoutFurtherAttempts(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://util.devi.tools/api/v2/authorize')),
            new Response(200, [], '{"status":"success","data":{"authorization":false}}'),
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $authorizer = $this->resilient($handler, $breaker, maxAttempts: 3);

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertSame(1, $handler->count(), 'third queued response must remain unused');
        $this->assertSame(0, $breaker->getFailCounter());
        $this->assertTrue($breaker->state()->isClose());
    }

    public function testAuthz02RetryThenClearWithinMaxAttempts(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://util.devi.tools/api/v2/authorize')),
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $authorizer = $this->resilient($handler, $breaker, maxAttempts: 3);

        $this->assertTrue($authorizer->authorize($this->transfer()));
        $consumed = 2 - $handler->count();
        $this->assertSame(2, $consumed);
        $this->assertLessThanOrEqual(3, $consumed);
        $this->assertTrue($breaker->state()->isClose());
    }

    public function testAuthz0304ExhaustBumpsFailOnceAndOpensAtThreshold(): void
    {
        $unavailable = static fn () => new ConnectException(
            'Connection refused',
            new Request('GET', 'https://util.devi.tools/api/v2/authorize'),
        );
        $handler = new MockHandler([
            $unavailable(),
            $unavailable(),
            $unavailable(),
            $unavailable(),
            $unavailable(),
            $unavailable(),
        ]);
        $breaker = new FakeCircuitBreaker();
        $authorizer = $this->resilient($handler, $breaker, maxAttempts: 3, failCounterThreshold: 2);

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertSame(1, $breaker->getFailCounter());
        $this->assertTrue($breaker->state()->isClose());
        $this->assertSame(3, 6 - $handler->count(), 'first authorize consumed three attempts');

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertTrue($breaker->state()->isOpen());
        $this->assertSame(0, $handler->count());
    }

    public function testAuthz05OpenSkipsUpstream(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $breaker->open();
        $authorizer = $this->resilient($handler, $breaker, duration: 60.0);

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertSame(1, $handler->count(), 'open breaker must not consume the queued response');
        $this->assertTrue($breaker->state()->isOpen());
    }

    public function testAuthz06HalfOpenSuccessClosesAndSubsequentClearWorks(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $breaker->open();
        $authorizer = $this->resilient($handler, $breaker, duration: 0.0);

        $this->assertTrue($authorizer->authorize($this->transfer()));
        $this->assertTrue($breaker->state()->isClose());

        $this->assertTrue($authorizer->authorize($this->transfer()));
        $this->assertSame(0, $handler->count());
        $this->assertTrue($breaker->state()->isClose());
    }

    public function testAuthz06HalfOpenUnavailableReopens(): void
    {
        $unavailable = static fn () => new ConnectException(
            'Connection refused',
            new Request('GET', 'https://util.devi.tools/api/v2/authorize'),
        );
        // Production-like knobs: one probe must reopen even when fail_counter is 5
        // and max_attempts is 3 — do not rely on threshold 1 to make reopen tautological.
        $handler = new MockHandler([
            $unavailable(),
            $unavailable(),
            $unavailable(),
        ]);
        $breaker = new FakeCircuitBreaker();
        $breaker->open();
        $authorizer = $this->resilient(
            $handler,
            $breaker,
            maxAttempts: 3,
            failCounterThreshold: 5,
            duration: 0.0,
        );

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertTrue($breaker->state()->isOpen());
        $this->assertSame(2, $handler->count(), 'half-open must be a single probe, not the retry loop');
    }

    public function testAuthz06HalfOpenDeclineClosesAsReachableSuccess(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":false}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $breaker->open();
        $authorizer = $this->resilient($handler, $breaker, duration: 0.0);

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertTrue($breaker->state()->isClose());
        $this->assertSame(0, $breaker->getFailCounter());
        $this->assertSame(0, $handler->count());
    }

    public function testHalfOpenSiblingsFailClosedWithoutUpstream(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $breaker->halfOpen();
        $authorizer = $this->resilient($handler, $breaker);

        $this->assertFalse($authorizer->authorize($this->transfer()));
        $this->assertSame(1, $handler->count(), 'in-flight / leftover half-open must not call upstream');
        $this->assertTrue($breaker->state()->isHalfOpen());
    }

    public function testDoesNotCallBreakerAttemptCoinFlip(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Infrastructure/Http/ResilientTransferAuthorizer.php');
        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/\$breaker->attempt\s*\(/',
            $source,
            'must not use Attempt::attempt() coin-flip via breaker->attempt()',
        );

        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $breaker = new FakeCircuitBreaker();
        $this->assertTrue($this->resilient($handler, $breaker)->authorize($this->transfer()));
        $this->assertSame(0, $breaker->attemptCalls);
    }

    private function resilient(
        MockHandler $handler,
        FakeCircuitBreaker $breaker,
        int $maxAttempts = 3,
        int $failCounterThreshold = 5,
        float $duration = 10.0,
    ): ResilientTransferAuthorizer {
        $factory = new CircuitBreakerFactory();
        $factory->set(self::BREAKER, $breaker);

        $inner = new DeviToolsAuthorizer(new RecordingClientFactory($handler));
        $container = $this->createMock(ContainerInterface::class);

        return new ResilientTransferAuthorizer(
            $inner,
            $factory,
            $container,
            self::BREAKER,
            $maxAttempts,
            0,
            0,
            $failCounterThreshold,
            1,
            $duration,
        );
    }

    private function transfer(): Transfer
    {
        return new Transfer(null, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-01-02 03:04:05'));
    }
}

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
