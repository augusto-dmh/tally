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

namespace HyperfTest\Integration\Application;

use App\Application\TransferFunds;
use App\Application\TransferFundsInput;
use App\Domain\Money;
use App\Domain\Port\Ledger;
use App\Infrastructure\Persistence\DbIdempotencyStore;
use App\Infrastructure\Persistence\DbTransactionRunner;
use App\Infrastructure\Persistence\DbTransferRepository;
use App\Infrastructure\Persistence\DbUserRepository;
use App\Infrastructure\Persistence\DbWalletRepository;
use DateTimeImmutable;
use Hyperf\DbConnection\Db;
use HyperfTest\Fake\FakeOutbox;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Integration\IntegrationTestCase;
use RuntimeException;

/**
 * Proves money-txn dual-write rollback under a real DbTransactionRunner: after
 * wallets and the transfer row are written, a ledger failure must restore
 * balances and leave neither transfers nor ledger legs.
 *
 * @internal
 * @coversNothing
 */
final class TransferFundsLedgerRollbackTest extends IntegrationTestCase
{
    public function testRollsBackWalletsTransferAndLegsWhenAppendThrowsAfterPersist(): void
    {
        $payerUserId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeUserId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerUserId, 100000);
        $payeeWalletId = $this->insertWallet($payeeUserId, 50000);

        $throwingLedger = new class implements Ledger {
            public function appendTransferLegs(
                string $journalId,
                int $transferId,
                int $payerWalletId,
                int $payeeWalletId,
                Money $amount,
                DateTimeImmutable $createdAt,
            ): void {
                throw new RuntimeException('ledger append failed after transfer persist');
            }

            public function appendOpeningLegs(
                string $journalId,
                int $walletId,
                Money $balance,
                DateTimeImmutable $createdAt,
            ): void {
            }

            public function totalCreditsCents(): int
            {
                return 0;
            }

            public function totalDebitsCents(): int
            {
                return 0;
            }

            public function walletNetsCents(): array
            {
                return [];
            }
        };

        $transferFunds = new TransferFunds(
            new DbTransactionRunner(),
            new DbUserRepository(),
            new DbWalletRepository(),
            new DbTransferRepository(),
            new FakeTransferAuthorizer(),
            new FakeOutbox(),
            new DbIdempotencyStore(),
            $throwingLedger,
        );

        $thrown = null;
        try {
            $transferFunds->execute(new TransferFundsInput(
                $payerUserId,
                $payeeUserId,
                Money::fromCents(1000),
            ));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('ledger append failed after transfer persist', $thrown->getMessage());
        $this->assertSame(100000, $this->balanceOf($payerWalletId));
        $this->assertSame(50000, $this->balanceOf($payeeWalletId));
        $this->assertSame(0, (int) Db::table('transfers')->count());
        $this->assertSame(0, (int) Db::table('ledger_entries')->count());
    }
}
