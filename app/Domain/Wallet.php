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

use App\Domain\Exception\InsufficientBalance;

final class Wallet
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        private Money $balance,
    ) {
    }

    public function balance(): Money
    {
        return $this->balance;
    }

    public function debit(Money $amount): void
    {
        if ($amount->cents() > $this->balance->cents()) {
            throw new InsufficientBalance(sprintf(
                'Wallet %d holds %d cents, which cannot cover a debit of %d cents.',
                $this->id,
                $this->balance->cents(),
                $amount->cents()
            ));
        }

        $this->balance = $this->balance->subtract($amount);
    }

    public function credit(Money $amount): void
    {
        $this->balance = $this->balance->add($amount);
    }
}
