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

final class TransferFundsInput
{
    public function __construct(
        public readonly int $payerId,
        public readonly int $payeeId,
        public readonly Money $amount,
    ) {
    }
}
