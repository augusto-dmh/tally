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

use App\Domain\Exception\NotificationFailed;
use App\Domain\Money;
use App\Domain\Transfer;
use App\Infrastructure\Http\DeviToolsNotifier;
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
final class DeviToolsNotifierTest extends TestCase
{
    public function testTellsTheServiceAboutTheTransferItAccepts(): void
    {
        $handler = new MockHandler([new Response(204)]);

        $this->notifierFor($handler)->notify($this->transfer());

        $request = $handler->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://util.devi.tools/api/v1/notify', (string) $request->getUri());
        $this->assertSame(
            ['transfer_id' => 7, 'payee_wallet_id' => 2, 'amount_cents' => 10050],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testFailsWhenTheServiceBreaks(): void
    {
        $handler = new MockHandler([new Response(500, [], 'Internal Server Error')]);

        $this->expectException(NotificationFailed::class);

        $this->notifierFor($handler)->notify($this->transfer());
    }

    public function testFailsWhenTheServiceRejectsTheNotification(): void
    {
        $handler = new MockHandler([new Response(400, [], '{"status":"fail"}')]);

        $this->expectException(NotificationFailed::class);

        $this->notifierFor($handler)->notify($this->transfer());
    }

    public function testFailsWhenTheServiceCannotBeReached(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'https://util.devi.tools/api/v1/notify')),
        ]);

        $this->expectException(NotificationFailed::class);

        $this->notifierFor($handler)->notify($this->transfer());
    }

    public function testBoundsHowLongItWaitsForTheService(): void
    {
        $factory = new RecordingClientFactory(new MockHandler([new Response(204)]));

        (new DeviToolsNotifier($factory))->notify($this->transfer());

        $this->assertArrayHasKey('timeout', $factory->options);
        $this->assertArrayHasKey('connect_timeout', $factory->options);
        $this->assertGreaterThan(0, $factory->options['timeout']);
        $this->assertGreaterThan(0, $factory->options['connect_timeout']);
    }

    public function testTellsTheServiceConfiguredForTheEnvironment(): void
    {
        $handler = new MockHandler([new Response(204)]);

        (new DeviToolsNotifier(new RecordingClientFactory($handler), 'https://stub.tally.test'))->notify($this->transfer());

        $this->assertSame('https://stub.tally.test/api/v1/notify', (string) $handler->getLastRequest()->getUri());
    }

    private function notifierFor(MockHandler $handler): DeviToolsNotifier
    {
        return new DeviToolsNotifier(new RecordingClientFactory($handler));
    }

    private function transfer(): Transfer
    {
        return new Transfer(7, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-01-02 03:04:05'));
    }
}
