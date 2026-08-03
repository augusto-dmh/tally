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

use App\Domain\Exception\InvalidAmount;
use App\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MoneyTest extends TestCase
{
    #[DataProvider('decimalStringProvider')]
    public function testItConvertsDecimalStringsToExactCents(string $decimal, int $expectedCents): void
    {
        $this->assertSame($expectedCents, Money::fromDecimalString($decimal)->cents());
    }

    public static function decimalStringProvider(): array
    {
        return [
            'two decimals' => ['100.50', 10050],
            'one decimal' => ['100.5', 10050],
            'no decimals' => ['100', 10000],
            'one cent' => ['0.01', 1],
            'zero' => ['0.00', 0],
            'float-lossy 0.29' => ['0.29', 29],
            'float-lossy 0.58' => ['0.58', 58],
            'float-lossy 1.15' => ['1.15', 115],
        ];
    }

    #[DataProvider('invalidDecimalStringProvider')]
    public function testItRejectsAmountsItCannotRepresentInCents(string $decimal): void
    {
        $this->expectException(InvalidAmount::class);

        Money::fromDecimalString($decimal);
    }

    public static function invalidDecimalStringProvider(): array
    {
        return [
            'three decimals' => ['100.505'],
            'sub-cent' => ['0.001'],
            'non-numeric' => ['abc'],
            'empty' => [''],
            'trailing dot' => ['100.'],
            'exponent form' => ['1e2'],
            'padded' => [' 100.50 '],
            'signed positive' => ['+100.50'],
            'negative' => ['-1.00'],
            'negative cent' => ['-0.01'],
        ];
    }

    public function testBothConstructionPathsProduceTheSameAmount(): void
    {
        $this->assertTrue(Money::fromCents(10050)->equals(Money::fromDecimalString('100.50')));
    }

    public function testItRejectsNegativeCents(): void
    {
        $this->expectException(InvalidAmount::class);

        Money::fromCents(-1);
    }

    public function testItAddsWithoutMutatingTheOperands(): void
    {
        $balance = Money::fromCents(10050);

        $credited = $balance->add(Money::fromCents(1));

        $this->assertSame(10051, $credited->cents());
        $this->assertSame(10050, $balance->cents());
    }

    public function testItSubtractsWithoutMutatingTheOperands(): void
    {
        $balance = Money::fromCents(10050);

        $debited = $balance->subtract(Money::fromCents(50));

        $this->assertSame(10000, $debited->cents());
        $this->assertSame(10050, $balance->cents());
    }

    public function testItSubtractsDownToExactlyZero(): void
    {
        $this->assertSame(0, Money::fromCents(10050)->subtract(Money::fromCents(10050))->cents());
    }

    public function testItRejectsASubtractionThatWouldGoBelowZero(): void
    {
        $this->expectException(InvalidAmount::class);

        Money::fromCents(10050)->subtract(Money::fromCents(10051));
    }

    public function testAmountsWithDifferentCentsAreNotEqual(): void
    {
        $this->assertFalse(Money::fromCents(10050)->equals(Money::fromCents(10051)));
    }
}
