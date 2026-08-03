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

use DateTimeImmutable;

final class Transfer
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $payerWalletId,
        public readonly int $payeeWalletId,
        public readonly Money $amount,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
