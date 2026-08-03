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

namespace App\Domain;

use App\Domain\Exception\InvalidAmount;

final class Money
{
    private function __construct(private readonly int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        if ($cents < 0) {
            throw new InvalidAmount(sprintf('An amount cannot be negative, got %d cents.', $cents));
        }

        return new self($cents);
    }

    /**
     * Parses a decimal amount without float arithmetic: floats cannot hold
     * exact cents, so the digits are read as integers.
     */
    public static function fromDecimalString(string $value): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new InvalidAmount(sprintf('The amount "%s" is not a decimal value with at most two decimal places.', $value));
        }

        $decimals = str_pad($matches[2] ?? '', 2, '0');

        return new self((int) $matches[1] * 100 + (int) $decimals);
    }

    public function add(self $other): self
    {
        return self::fromCents($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return self::fromCents($this->cents - $other->cents);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }
}
