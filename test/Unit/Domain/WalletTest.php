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

use App\Domain\Exception\InsufficientBalance;
use App\Domain\Money;
use App\Domain\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class WalletTest extends TestCase
{
    public function testItCreditsTheExactAmount(): void
    {
        $wallet = new Wallet(1, 1, Money::fromCents(10000));

        $wallet->credit(Money::fromCents(5050));

        $this->assertSame(15050, $wallet->balance()->cents());
    }

    public function testItDebitsTheExactAmount(): void
    {
        $wallet = new Wallet(1, 1, Money::fromCents(10000));

        $wallet->debit(Money::fromCents(2550));

        $this->assertSame(7450, $wallet->balance()->cents());
    }

    public function testItDebitsTheWholeBalanceDownToZero(): void
    {
        $wallet = new Wallet(1, 1, Money::fromCents(10050));

        $wallet->debit(Money::fromCents(10050));

        $this->assertSame(0, $wallet->balance()->cents());
    }

    public function testItRejectsADebitLargerThanTheBalanceAndKeepsIt(): void
    {
        $wallet = new Wallet(1, 1, Money::fromCents(10050));

        try {
            $wallet->debit(Money::fromCents(10051));
            $this->fail('Debiting more than the balance should have thrown ' . InsufficientBalance::class);
        } catch (InsufficientBalance) {
        }

        $this->assertSame(10050, $wallet->balance()->cents());
    }
}
