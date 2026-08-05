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

namespace HyperfTest\Unit\Domain;

use App\Domain\Money;
use App\Domain\Wallet;
use HyperfTest\Fake\FakeWalletRepository;
use PHPUnit\Framework\TestCase;

/**
 * Spec anchors: CONC-01 / CONC-02 — money movement must read wallets under
 * row locks; the fake records ForUpdate so unit tests can assert the lock path.
 *
 * @internal
 * @coversNothing
 */
class WalletRepositoryForUpdateTest extends TestCase
{
    public function testFindByUserIdForUpdateReturnsTheWalletAndRecordsTheLock(): void
    {
        $repo = new FakeWalletRepository();
        $repo->save(new Wallet(11, 1, Money::fromCents(10050)));

        $wallet = $repo->findByUserIdForUpdate(1);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame(11, $wallet->id);
        $this->assertSame(1, $wallet->userId);
        $this->assertSame(10050, $wallet->balance()->cents());
        $this->assertSame([1], $repo->forUpdateUserIds);
    }

    public function testFindByUserIdForUpdateReturnsNullWhenMissingAndStillRecordsTheLock(): void
    {
        $repo = new FakeWalletRepository();

        $this->assertNull($repo->findByUserIdForUpdate(99));
        $this->assertSame([99], $repo->forUpdateUserIds);
    }

    public function testUnlockedFindDoesNotRecordAForUpdateLock(): void
    {
        $repo = new FakeWalletRepository();
        $repo->save(new Wallet(11, 1, Money::fromCents(10050)));

        $repo->findByUserId(1);

        $this->assertSame([], $repo->forUpdateUserIds);
    }
}
