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
use App\Domain\Port\TransferAuthorizer;
use App\Domain\Transfer;
use App\Infrastructure\Http\AuthorizerAttempt;
use App\Infrastructure\Http\AuthorizerUnavailable;
use App\Infrastructure\Http\DeviToolsAuthorizer;
use App\Infrastructure\Http\ResilientTransferAuthorizer;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hyperf\CircuitBreaker\CircuitBreakerFactory;
use HyperfTest\Fake\FakeCircuitBreaker;
use HyperfTest\Fake\RecordingClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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

        $this->assertFalse($authorizer->attempt($this->transfer()));
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

        $this->assertFalse($authorizer->attempt($this->transfer()));
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

        $this->assertTrue($authorizer->attempt($this->transfer()));
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

        $this->assertThrowsUnavailable($authorizer);
        $this->assertSame(1, $breaker->getFailCounter());
        $this->assertTrue($breaker->state()->isClose());
        $this->assertSame(3, 6 - $handler->count(), 'first authorize consumed three attempts');

        $this->assertThrowsUnavailable($authorizer);
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

        $this->assertThrowsUnavailable($authorizer);
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

        $this->assertTrue($authorizer->attempt($this->transfer()));
        $this->assertTrue($breaker->state()->isClose());

        $this->assertTrue($authorizer->attempt($this->transfer()));
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

        $this->assertThrowsUnavailable($authorizer);
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

        $this->assertFalse($authorizer->attempt($this->transfer()));
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

        $this->assertThrowsUnavailable($authorizer);
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
        $authorizer = $this->resilient($handler, $breaker);
        $this->assertInstanceOf(AuthorizerAttempt::class, $authorizer);
        $this->assertNotInstanceOf(TransferAuthorizer::class, $authorizer);
        $this->assertTrue($authorizer->attempt($this->transfer()));
        $this->assertSame(0, $breaker->attemptCalls);
    }

    private function assertThrowsUnavailable(ResilientTransferAuthorizer $authorizer): void
    {
        try {
            $authorizer->attempt($this->transfer());
            $this->fail(AuthorizerUnavailable::class . ' was not thrown');
        } catch (AuthorizerUnavailable) {
        }
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
