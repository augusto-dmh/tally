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
use App\Infrastructure\Http\AuthorizerUnavailable;
use App\Infrastructure\Http\DeviToolsAuthorizer;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HyperfTest\Fake\RecordingClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class DeviToolsAuthorizerTest extends TestCase
{
    public function testClearsWhenTheServiceAuthorizesTheTransfer(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        $cleared = $this->authorizerFor($handler)->attempt($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame('GET', $handler->getLastRequest()->getMethod());
        $this->assertSame('https://util.devi.tools/api/v2/authorize', (string) $handler->getLastRequest()->getUri());
        $this->assertSame(0, $handler->count());
    }

    public function testReturnsFalseOnExplicitDeclineWithoutThrowing(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":false}}'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->attempt($this->transfer()));
        $this->assertSame(0, $handler->count());
    }

    public function testReturnsFalseOnTheForbiddenRefusalBodyWithoutThrowing(): void
    {
        $handler = new MockHandler([
            new Response(403, [], '{"status":"fail","data":{"authorization":false}}'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->attempt($this->transfer()));
        $this->assertSame(0, $handler->count());
    }

    public function testThrowsWhenTheServiceBreaks(): void
    {
        $handler = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $this->expectException(AuthorizerUnavailable::class);

        $this->authorizerFor($handler)->attempt($this->transfer());
    }

    public function testThrowsWhenAnErrorStatusCarriesAnAuthorizingBody(): void
    {
        $handler = new MockHandler([
            new Response(503, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        $this->expectException(AuthorizerUnavailable::class);

        $this->authorizerFor($handler)->attempt($this->transfer());
    }

    public function testThrowsOnASuccessStatusCarryingAnUnreadableBody(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '<html>we are down for maintenance</html>'),
        ]);

        $this->expectException(AuthorizerUnavailable::class);

        $this->authorizerFor($handler)->attempt($this->transfer());
    }

    public function testThrowsOnASuccessStatusMissingTheAuthorizationField(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{}}'),
        ]);

        $this->expectException(AuthorizerUnavailable::class);

        $this->authorizerFor($handler)->attempt($this->transfer());
    }

    public function testThrowsWhenTheServiceCannotBeReached(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://util.devi.tools/api/v2/authorize')),
        ]);

        $this->expectException(AuthorizerUnavailable::class);

        $this->authorizerFor($handler)->attempt($this->transfer());
    }

    public function testAppliesConfiguredTimeoutsToTheHttpClient(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $factory = new RecordingClientFactory($handler);

        (new DeviToolsAuthorizer($factory, DeviToolsAuthorizer::DEFAULT_BASE_URI, 4.5, 1.25))->attempt($this->transfer());

        $this->assertSame(4.5, $factory->options['timeout']);
        $this->assertSame(1.25, $factory->options['connect_timeout']);
    }

    public function testUsesDesignDefaultTimeoutsWhenNoneArePassed(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $factory = new RecordingClientFactory($handler);

        (new DeviToolsAuthorizer($factory))->attempt($this->transfer());

        $this->assertSame(5.0, $factory->options['timeout']);
        $this->assertSame(2.0, $factory->options['connect_timeout']);
    }

    public function testAsksTheServiceConfiguredForTheEnvironment(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        (new DeviToolsAuthorizer(new RecordingClientFactory($handler), 'https://stub.tally.test'))->attempt($this->transfer());

        $this->assertSame('https://stub.tally.test/api/v2/authorize', (string) $handler->getLastRequest()->getUri());
    }

    private function authorizerFor(MockHandler $handler): DeviToolsAuthorizer
    {
        return new DeviToolsAuthorizer(new RecordingClientFactory($handler));
    }

    private function transfer(): Transfer
    {
        return new Transfer(null, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-01-02 03:04:05'));
    }
}
