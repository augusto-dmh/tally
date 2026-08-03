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

namespace HyperfTest\Integration\Persistence;

use App\Domain\Money;
use App\Domain\Transfer;
use App\Infrastructure\Persistence\DbTransactionRunner;
use App\Infrastructure\Persistence\DbTransferRepository;
use App\Infrastructure\Persistence\DbWalletRepository;
use DateTimeImmutable;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
final class DbTransactionRunnerTest extends IntegrationTestCase
{
    private int $payerWalletId;

    private int $payeeWalletId;

    private int $payerUserId;

    private int $payeeUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payerUserId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $this->payeeUserId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $this->payerWalletId = $this->insertWallet($this->payerUserId, 100000);
        $this->payeeWalletId = $this->insertWallet($this->payeeUserId, 50000);
    }

    public function testKeepsEveryWriteAndReturnsWhatTheOperationReturned(): void
    {
        $result = (new DbTransactionRunner())->run(function (): string {
            $this->moveOneThousandCents();

            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertSame(99000, $this->balanceOf($this->payerWalletId));
        $this->assertSame(51000, $this->balanceOf($this->payeeWalletId));
        $this->assertSame(1, Db::table('transfers')->count());
    }

    public function testDiscardsEveryWriteWhenTheOperationThrows(): void
    {
        $thrown = null;

        try {
            (new DbTransactionRunner())->run(function (): void {
                $this->moveOneThousandCents();

                throw new RuntimeException('the payee could not be notified');
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('the payee could not be notified', $thrown->getMessage());
        $this->assertSame(100000, $this->balanceOf($this->payerWalletId));
        $this->assertSame(50000, $this->balanceOf($this->payeeWalletId));
        $this->assertSame(0, Db::table('transfers')->count());
    }

    /**
     * The writes a transfer makes: both wallet balances and the transfer row.
     */
    private function moveOneThousandCents(): void
    {
        $wallets = new DbWalletRepository();
        $amount = Money::fromCents(1000);

        $payerWallet = $wallets->findByUserId($this->payerUserId);
        $payerWallet->debit($amount);
        $wallets->save($payerWallet);

        $payeeWallet = $wallets->findByUserId($this->payeeUserId);
        $payeeWallet->credit($amount);
        $wallets->save($payeeWallet);

        (new DbTransferRepository())->add(new Transfer(
            null,
            $this->payerWalletId,
            $this->payeeWalletId,
            $amount,
            new DateTimeImmutable('2026-01-02 03:04:05'),
        ));
    }
}
