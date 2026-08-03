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
use App\Infrastructure\Persistence\DbWalletRepository;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
final class DbWalletRepositoryTest extends IntegrationTestCase
{
    public function testFindsTheWalletOfAUserWithItsBalanceInCents(): void
    {
        $userId = $this->insertUser('11111111111');
        $walletId = $this->insertWallet($userId, 100000);

        $wallet = (new DbWalletRepository())->findByUserId($userId);

        $this->assertNotNull($wallet);
        $this->assertSame($walletId, $wallet->id);
        $this->assertSame($userId, $wallet->userId);
        $this->assertSame(100000, $wallet->balance()->cents());
    }

    public function testReturnsNullWhenTheUserHasNoWallet(): void
    {
        $userId = $this->insertUser('11111111111');

        $this->assertNull((new DbWalletRepository())->findByUserId($userId));
    }

    public function testSavesTheBalanceTheWalletCarriesNow(): void
    {
        $userId = $this->insertUser('11111111111');
        $walletId = $this->insertWallet($userId, 100000);
        $repository = new DbWalletRepository();

        $wallet = $repository->findByUserId($userId);
        $this->assertNotNull($wallet);
        $wallet->debit(Money::fromCents(2550));
        $repository->save($wallet);

        $this->assertSame(97450, $this->balanceOf($walletId));
        $reloaded = $repository->findByUserId($userId);
        $this->assertNotNull($reloaded);
        $this->assertSame(97450, $reloaded->balance()->cents());
        $this->assertNotSame(
            '2026-01-01 00:00:00',
            Db::table('wallets')->where('id', $walletId)->value('updated_at')
        );
    }

    public function testLeavesOtherWalletsUntouchedWhenOneIsSaved(): void
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $this->insertWallet($payerId, 100000);
        $payeeWalletId = $this->insertWallet($payeeId, 50000);
        $repository = new DbWalletRepository();

        $payerWallet = $repository->findByUserId($payerId);
        $this->assertNotNull($payerWallet);
        $payerWallet->debit(Money::fromCents(1000));
        $repository->save($payerWallet);

        $this->assertSame(50000, $this->balanceOf($payeeWalletId));
    }
}
