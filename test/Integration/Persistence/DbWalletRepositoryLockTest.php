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

use App\Infrastructure\Persistence\DbTransactionRunner;
use App\Infrastructure\Persistence\DbWalletRepository;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Hyperf\Engine\Channel;
use HyperfTest\Integration\IntegrationTestCase;

/**
 * Spec anchors: CONC-01 / CONC-02 — wallet rows are read with SELECT … FOR UPDATE
 * inside a transaction so concurrent writers wait for the lock holder.
 *
 * @internal
 * @coversNothing
 */
final class DbWalletRepositoryLockTest extends IntegrationTestCase
{
    public function testFindByUserIdForUpdateReturnsTheWalletInsideATransaction(): void
    {
        $userId = $this->insertUser('11111111111');
        $walletId = $this->insertWallet($userId, 100000);

        $wallet = (new DbTransactionRunner())->run(
            static fn () => (new DbWalletRepository())->findByUserIdForUpdate($userId)
        );

        $this->assertNotNull($wallet);
        $this->assertSame($walletId, $wallet->id);
        $this->assertSame($userId, $wallet->userId);
        $this->assertSame(100000, $wallet->balance()->cents());
    }

    public function testFindByUserIdForUpdateReturnsNullWhenTheUserHasNoWallet(): void
    {
        $userId = $this->insertUser('11111111111');

        $wallet = (new DbTransactionRunner())->run(
            static fn () => (new DbWalletRepository())->findByUserIdForUpdate($userId)
        );

        $this->assertNull($wallet);
    }

    public function testForUpdateBlocksAConcurrentWriterUntilTheTransactionCommits(): void
    {
        $userId = $this->insertUser('11111111111');
        $walletId = $this->insertWallet($userId, 100000);
        $order = new Channel(4);

        $parallel = new Parallel(2);
        $parallel->add(function () use ($userId, $walletId, $order): void {
            Db::beginTransaction();
            try {
                (new DbWalletRepository())->findByUserIdForUpdate($userId);
                $order->push('holder_locked');
                usleep(300_000);
                Db::table('wallets')->where('id', $walletId)->update([
                    'balance_cents' => 50000,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                Db::commit();
                $order->push('holder_committed');
            } catch (\Throwable $exception) {
                Db::rollBack();
                throw $exception;
            }
        });
        $parallel->add(function () use ($walletId, $order): void {
            $this->assertSame('holder_locked', $order->pop(5.0));
            Db::table('wallets')->where('id', $walletId)->update([
                'balance_cents' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $order->push('waiter_wrote');
        });
        $parallel->wait();

        $this->assertSame('holder_committed', $order->pop(1.0));
        $this->assertSame('waiter_wrote', $order->pop(1.0));
        $this->assertSame(1, $this->balanceOf($walletId));
    }
}
