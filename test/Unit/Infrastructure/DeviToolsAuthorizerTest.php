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
    public function testAuthorizesWhenTheServiceClearsTheTransfer(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        $authorized = $this->authorizerFor($handler)->authorize($this->transfer());

        $this->assertTrue($authorized);
        $this->assertSame('GET', $handler->getLastRequest()->getMethod());
        $this->assertSame('https://util.devi.tools/api/v2/authorize', (string) $handler->getLastRequest()->getUri());
    }

    public function testDeclinesWhenTheServiceAnswersThatItDoesNotAuthorize(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":false}}'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testDeclinesOnTheForbiddenAnswerTheServiceSendsForARefusal(): void
    {
        $handler = new MockHandler([
            new Response(403, [], '{"status":"fail","data":{"authorization":false}}'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testFailsClosedWhenTheServiceBreaks(): void
    {
        $handler = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testFailsClosedWhenAnErrorStatusCarriesAnAuthorizingBody(): void
    {
        $handler = new MockHandler([
            new Response(503, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testFailsClosedOnASuccessStatusCarryingAnUnreadableBody(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '<html>we are down for maintenance</html>'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testFailsClosedOnASuccessStatusMissingTheAuthorizationField(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{}}'),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testFailsClosedWhenTheServiceCannotBeReached(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://util.devi.tools/api/v2/authorize')),
        ]);

        $this->assertFalse($this->authorizerFor($handler)->authorize($this->transfer()));
    }

    public function testBoundsHowLongItWaitsForTheService(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);
        $factory = new RecordingClientFactory($handler);

        (new DeviToolsAuthorizer($factory))->authorize($this->transfer());

        $this->assertArrayHasKey('timeout', $factory->options);
        $this->assertArrayHasKey('connect_timeout', $factory->options);
        $this->assertGreaterThan(0, $factory->options['timeout']);
        $this->assertGreaterThan(0, $factory->options['connect_timeout']);
    }

    public function testAsksTheServiceConfiguredForTheEnvironment(): void
    {
        $handler = new MockHandler([
            new Response(200, [], '{"status":"success","data":{"authorization":true}}'),
        ]);

        (new DeviToolsAuthorizer(new RecordingClientFactory($handler), 'https://stub.tally.test'))->authorize($this->transfer());

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
