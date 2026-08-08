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

namespace HyperfTest\Integration\Command;

use App\Command\OutboxDrainCommand;
use App\Domain\OutboxEventType;
use App\Domain\Port\Outbox;
use App\Domain\Port\TransferNotifier;
use DateTimeImmutable;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use HyperfTest\Fake\FakeTransferNotifier;
use HyperfTest\Integration\IntegrationTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Spec anchors: OUTB-12 (outbox:drain exits 0 and drains a pending row).
 *
 * @internal
 * @coversNothing
 */
final class OutboxDrainCommandTest extends IntegrationTestCase
{
    public function testDrainsAPendingRowAndExitsZero(): void
    {
        [$payerWalletId, $payeeWalletId, $transferId] = $this->seedTransferPair();
        $outbox = ApplicationContext::getContainer()->get(Outbox::class);
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            [
                'transfer_id' => $transferId,
                'payer_wallet_id' => $payerWalletId,
                'payee_wallet_id' => $payeeWalletId,
                'amount_cents' => 1000,
            ],
            new DateTimeImmutable('2026-08-08 12:00:00'),
        );

        /** @var FakeTransferNotifier $notifier */
        $notifier = ApplicationContext::getContainer()->get(TransferNotifier::class);
        $notifier->fails = false;
        $notifier->notified = [];

        $exitCode = $this->runDrain();

        $this->assertSame(0, $exitCode);
        $this->assertSame('done', Db::table('outbox')->where('transfer_id', $transferId)->value('status'));
        $this->assertCount(1, $notifier->notified);
        $this->assertSame($transferId, $notifier->notified[0]->id);
    }

    /**
     * @return array{0: int, 1: int, 2: int} payerWalletId, payeeWalletId, transferId
     */
    private function seedTransferPair(): array
    {
        $payerUserId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeUserId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerUserId, 100000);
        $payeeWalletId = $this->insertWallet($payeeUserId, 50000);
        $transferId = (int) Db::table('transfers')->insertGetId([
            'payer_wallet_id' => $payerWalletId,
            'payee_wallet_id' => $payeeWalletId,
            'amount_cents' => 1000,
            'created_at' => '2026-08-08 12:00:00',
        ]);

        return [$payerWalletId, $payeeWalletId, $transferId];
    }

    private function runDrain(): int
    {
        $container = ApplicationContext::getContainer();
        $command = $container->get(OutboxDrainCommand::class);

        return $command->run(new ArrayInput([]), new NullOutput());
    }
}
