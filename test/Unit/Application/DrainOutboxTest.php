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

namespace HyperfTest\Unit\Application;

use App\Application\DrainOutbox;
use App\Domain\OutboxEventType;
use DateTimeImmutable;
use HyperfTest\Fake\FakeOutbox;
use HyperfTest\Fake\FakeTransferNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec anchors: OUTB-04 (claim → notify → done), OUTB-06 (retry backoff),
 * OUTB-07 (dead at max attempts), unknown event_type → dead.
 *
 * @internal
 * @coversNothing
 */
class DrainOutboxTest extends TestCase
{
    public function testSuccessfulNotifyMarksDone(): void
    {
        $outbox = new FakeOutbox();
        $notifier = new FakeTransferNotifier();
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            42,
            [
                'transfer_id' => 42,
                'payer_wallet_id' => 11,
                'payee_wallet_id' => 22,
                'amount_cents' => 2550,
            ],
            $now,
        );

        $result = (new DrainOutbox($outbox, $notifier, new NullLogger(), 8, 10, 300))
            ->execute($now);

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->done);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->dead);
        $this->assertSame('done', $outbox->messages[0]['status']);
        $this->assertCount(1, $notifier->notified);
        $this->assertSame(42, $notifier->notified[0]->id);
        $this->assertSame(11, $notifier->notified[0]->payerWalletId);
        $this->assertSame(22, $notifier->notified[0]->payeeWalletId);
        $this->assertSame(2550, $notifier->notified[0]->amount->cents());
    }

    public function testNotifyFailureRetriesWithBackoff(): void
    {
        $outbox = new FakeOutbox();
        $notifier = new FakeTransferNotifier();
        $notifier->fails = true;
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            7,
            [
                'transfer_id' => 7,
                'payer_wallet_id' => 1,
                'payee_wallet_id' => 2,
                'amount_cents' => 100,
            ],
            $now,
        );

        $result = (new DrainOutbox($outbox, $notifier, new NullLogger(), maxAttempts: 3, batchSize: 10, backoffCapSeconds: 300))
            ->execute($now);

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->done);
        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->dead);

        $row = $outbox->messages[0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, $row['attempts']);
        $this->assertEquals($now->modify('+1 seconds'), $row['available_at']);
        $this->assertSame('The fake notifier was told to fail.', $row['last_error']);
    }

    public function testNotifyFailuresReachDeadAtMaxAttempts(): void
    {
        $outbox = new FakeOutbox();
        $notifier = new FakeTransferNotifier();
        $notifier->fails = true;
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            9,
            [
                'transfer_id' => 9,
                'payer_wallet_id' => 1,
                'payee_wallet_id' => 2,
                'amount_cents' => 50,
            ],
            $now,
        );

        $drain = new DrainOutbox($outbox, $notifier, new NullLogger(), maxAttempts: 2, batchSize: 10, backoffCapSeconds: 300);

        $first = $drain->execute($now);
        $this->assertSame(1, $first->failed);
        $this->assertSame(0, $first->dead);
        $this->assertSame('pending', $outbox->messages[0]['status']);
        $this->assertSame(1, $outbox->messages[0]['attempts']);

        $second = $drain->execute($outbox->messages[0]['available_at']);
        $this->assertSame(0, $second->failed);
        $this->assertSame(1, $second->dead);
        $this->assertSame('dead', $outbox->messages[0]['status']);
        $this->assertSame(2, $outbox->messages[0]['attempts']);
        $this->assertSame('The fake notifier was told to fail.', $outbox->messages[0]['last_error']);
    }

    public function testUnknownEventTypeIsMarkedDead(): void
    {
        $outbox = new FakeOutbox();
        $notifier = new FakeTransferNotifier();
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $outbox->enqueue(
            'transfer.unknown',
            3,
            ['transfer_id' => 3],
            $now,
        );

        $result = (new DrainOutbox($outbox, $notifier, new NullLogger()))->execute($now);

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->done);
        $this->assertSame(0, $result->failed);
        $this->assertSame(1, $result->dead);
        $this->assertSame('dead', $outbox->messages[0]['status']);
        $this->assertSame('unknown_event_type', $outbox->messages[0]['last_error']);
        $this->assertSame([], $notifier->notified);
    }
}
