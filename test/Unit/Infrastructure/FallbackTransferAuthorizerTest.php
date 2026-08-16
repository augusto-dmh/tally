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
use App\Infrastructure\Http\AuthorizerAttempt;
use App\Infrastructure\Http\AuthorizerUnavailable;
use App\Infrastructure\Http\DeviToolsAuthorizer;
use App\Infrastructure\Http\FallbackTransferAuthorizer;
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
 * @internal
 * @coversNothing
 */
final class FallbackTransferAuthorizerTest extends TestCase
{
    public function testAuthorizeReturnsTrueWhenTheFirstLeafClearsWithoutCallingLaterLeaves(): void
    {
        $primary = new FakeAuthorizerAttempt(true);
        $secondary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(0, $secondary->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheFirstLeafDeclinesWithoutCallingLaterLeaves(): void
    {
        $primary = new FakeAuthorizerAttempt(false);
        $secondary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(0, $secondary->calls);
    }

    public function testAuthorizeReturnsTrueWhenTheFirstLeafIsUnavailableAndALaterLeafClears(): void
    {
        $primary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());
        $secondary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $secondary->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheNextLeafDeclinesAfterAnUnavailableLeaf(): void
    {
        $primary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());
        $secondary = new FakeAuthorizerAttempt(false);
        $tertiary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary, $tertiary]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $secondary->calls);
        $this->assertSame(0, $tertiary->calls);
    }

    public function testAuthorizeReturnsFalseWhenEveryLeafIsUnavailable(): void
    {
        $primary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());
        $secondary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $secondary->calls);
    }

    public function testAuthorizeReturnsTrueWhenTheOnlyLeafClears(): void
    {
        $leaf = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$leaf]))->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $leaf->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheOnlyLeafDeclines(): void
    {
        $leaf = new FakeAuthorizerAttempt(false);

        $cleared = (new FallbackTransferAuthorizer([$leaf]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $leaf->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheOnlyLeafIsUnavailable(): void
    {
        $leaf = new FakeAuthorizerAttempt(new AuthorizerUnavailable());

        $cleared = (new FallbackTransferAuthorizer([$leaf]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $leaf->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheLeafListIsEmpty(): void
    {
        $cleared = (new FallbackTransferAuthorizer([]))->authorize($this->transfer());

        $this->assertFalse($cleared);
    }

    public function testSwappingLeafOrderChangesWhoIsTriedFirst(): void
    {
        $listedFirst = new FakeAuthorizerAttempt(true);
        $listedSecond = new FakeAuthorizerAttempt(true);

        $asListed = (new FallbackTransferAuthorizer([$listedFirst, $listedSecond]))->authorize($this->transfer());

        $this->assertTrue($asListed);
        $this->assertSame(1, $listedFirst->calls);
        $this->assertSame(0, $listedSecond->calls);

        $swappedFirst = new FakeAuthorizerAttempt(true);
        $swappedSecond = new FakeAuthorizerAttempt(true);

        $swapped = (new FallbackTransferAuthorizer([$swappedSecond, $swappedFirst]))->authorize($this->transfer());

        $this->assertTrue($swapped);
        $this->assertSame(1, $swappedSecond->calls);
        $this->assertSame(0, $swappedFirst->calls);
    }

    public function testFall04OpenPrimaryIssuesZeroHttpAndStillTriesSecondary(): void
    {
        $primaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $secondaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $primaryBreaker = new FakeCircuitBreaker();
        $primaryBreaker->open();
        $secondaryBreaker = new FakeCircuitBreaker();
        $primaryRemainingBefore = $primaryHandler->count();

        $cleared = $this->fallbackOfRealLeaves(
            $primaryHandler,
            $primaryBreaker,
            $secondaryHandler,
            $secondaryBreaker,
            primaryDuration: 60.0,
        )->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame($primaryRemainingBefore, $primaryHandler->count(), 'open primary must not consume queued HTTP');
        $this->assertSame(0, $secondaryHandler->count(), 'secondary must be attempted');
    }

    public function testFall04OpenPrimaryIssuesZeroHttpWhenSecondaryDeclines(): void
    {
        $primaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $secondaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":false}}'),
        ]);
        $primaryBreaker = new FakeCircuitBreaker();
        $primaryBreaker->open();
        $secondaryBreaker = new FakeCircuitBreaker();
        $primaryRemainingBefore = $primaryHandler->count();

        $cleared = $this->fallbackOfRealLeaves(
            $primaryHandler,
            $primaryBreaker,
            $secondaryHandler,
            $secondaryBreaker,
            primaryDuration: 60.0,
        )->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame($primaryRemainingBefore, $primaryHandler->count(), 'open primary must not consume queued HTTP');
        $this->assertSame(0, $secondaryHandler->count(), 'secondary must be attempted');
    }

    public function testFall01RealLeavesPrimaryClearDoesNotCallSecondary(): void
    {
        $primaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $secondaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        $cleared = $this->fallbackOfRealLeaves(
            $primaryHandler,
            new FakeCircuitBreaker(),
            $secondaryHandler,
            new FakeCircuitBreaker(),
        )->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(0, $primaryHandler->count());
        $this->assertSame(1, $secondaryHandler->count(), 'secondary queued success must remain unused');
    }

    public function testFall03RealLeavesPrimaryExhaustThenSecondaryClear(): void
    {
        $unavailable = static fn () => new ConnectException(
            'Connection refused',
            new Request('GET', 'https://util.devi.tools/api/v2/authorize'),
        );
        $primaryHandler = new MockHandler([
            $unavailable(),
            $unavailable(),
            $unavailable(),
            $unavailable(),
        ]);
        $secondaryHandler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        $cleared = $this->fallbackOfRealLeaves(
            $primaryHandler,
            new FakeCircuitBreaker(),
            $secondaryHandler,
            new FakeCircuitBreaker(),
            maxAttempts: 3,
        )->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $primaryHandler->count(), 'primary must stop after max_attempts');
        $this->assertSame(0, $secondaryHandler->count(), 'secondary must be attempted');
    }

    public function testProductionDependenciesBindFallbackAsThePort(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/config/autoload/dependencies.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('return new FallbackTransferAuthorizer($leaves);', $source);
        $this->assertStringNotContainsString('return new ResilientTransferAuthorizer(', $source);
        $this->assertStringNotContainsString('return new DeviToolsAuthorizer(', $source);
    }

    private function fallbackOfRealLeaves(
        MockHandler $primaryHandler,
        FakeCircuitBreaker $primaryBreaker,
        MockHandler $secondaryHandler,
        FakeCircuitBreaker $secondaryBreaker,
        int $maxAttempts = 3,
        float $primaryDuration = 10.0,
    ): FallbackTransferAuthorizer {
        $factory = new CircuitBreakerFactory();
        $factory->set('transfer.authorizer.primary', $primaryBreaker);
        $factory->set('transfer.authorizer.fallback', $secondaryBreaker);
        $container = $this->createMock(ContainerInterface::class);

        $primary = new ResilientTransferAuthorizer(
            new DeviToolsAuthorizer(new RecordingClientFactory($primaryHandler)),
            $factory,
            $container,
            'transfer.authorizer.primary',
            $maxAttempts,
            0,
            0,
            5,
            1,
            $primaryDuration,
        );
        $secondary = new ResilientTransferAuthorizer(
            new DeviToolsAuthorizer(new RecordingClientFactory($secondaryHandler)),
            $factory,
            $container,
            'transfer.authorizer.fallback',
            $maxAttempts,
            0,
            0,
            5,
            1,
            10.0,
        );

        return new FallbackTransferAuthorizer([$primary, $secondary]);
    }

    private function transfer(): Transfer
    {
        return new Transfer(null, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-01-02 03:04:05'));
    }
}

final class FakeAuthorizerAttempt implements AuthorizerAttempt
{
    public int $calls = 0;

    public function __construct(
        private readonly bool|AuthorizerUnavailable $outcome,
    ) {
    }

    public function attempt(Transfer $transfer): bool
    {
        ++$this->calls;

        if ($this->outcome instanceof AuthorizerUnavailable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
