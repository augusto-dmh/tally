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

namespace HyperfTest\Fake;

use App\Domain\Port\TransferRepository;
use App\Domain\Transfer;

final class FakeTransferRepository implements TransferRepository
{
    /** @var array<int, Transfer> */
    public array $added = [];

    public int $nextId = 1;

    public function add(Transfer $transfer): Transfer
    {
        $persisted = new Transfer(
            $this->nextId++,
            $transfer->payerWalletId,
            $transfer->payeeWalletId,
            $transfer->amount,
            $transfer->createdAt,
        );

        $this->added[] = $persisted;

        return $persisted;
    }
}
