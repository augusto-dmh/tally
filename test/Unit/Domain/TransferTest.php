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
use App\Domain\Transfer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class TransferTest extends TestCase
{
    public function testAnUnpersistedTransferCarriesNoIdAndKeepsTheMovementItWasGiven(): void
    {
        $transfer = new Transfer(null, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-08-02 12:00:00'));

        $this->assertNull($transfer->id);
        $this->assertSame(1, $transfer->payerWalletId);
        $this->assertSame(2, $transfer->payeeWalletId);
        $this->assertSame(10050, $transfer->amount->cents());
        $this->assertSame('2026-08-02 12:00:00', $transfer->createdAt->format('Y-m-d H:i:s'));
    }

    public function testAPersistedTransferCarriesItsId(): void
    {
        $transfer = new Transfer(7, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-08-02 12:00:00'));

        $this->assertSame(7, $transfer->id);
    }
}
