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
 * Spec anchors: LEDG-04 / LEDG-06 — reconcile compares each wallet’s
 * balance_cents projection to the net of that wallet’s ledger legs; the
 * repository port must expose the full id → balance_cents map.
 *
 * @internal
 * @coversNothing
 */
class WalletRepositoryBalanceMapTest extends TestCase
{
    public function testListBalanceCentsByWalletIdReturnsEmptyMapWhenNoWalletsAreSeeded(): void
    {
        $repo = new FakeWalletRepository();

        $this->assertSame([], $repo->listBalanceCentsByWalletId());
    }

    public function testListBalanceCentsByWalletIdReturnsSeededWalletBalancesKeyedByWalletId(): void
    {
        $repo = new FakeWalletRepository();
        $repo->save(new Wallet(11, 1, Money::fromCents(10050)));
        $repo->save(new Wallet(22, 2, Money::fromCents(0)));
        $repo->save(new Wallet(33, 3, Money::fromCents(25000)));

        $this->assertSame([
            11 => 10050,
            22 => 0,
            33 => 25000,
        ], $repo->listBalanceCentsByWalletId());
    }
}
