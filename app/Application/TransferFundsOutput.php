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

namespace App\Application;

use App\Domain\Money;
use DateTimeImmutable;

/**
 * Carries the parties as user ids, the way the caller named them, rather than
 * as the wallet ids the transfer row holds.
 */
final class TransferFundsOutput
{
    public function __construct(
        public readonly int $id,
        public readonly int $payerId,
        public readonly int $payeeId,
        public readonly Money $amount,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
