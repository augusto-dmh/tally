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

use App\Domain\Exception\NotificationFailed;
use App\Domain\Port\TransferNotifier;
use App\Domain\Transfer;

final class FakeTransferNotifier implements TransferNotifier
{
    public bool $fails = false;

    /** @var array<int, Transfer> */
    public array $notified = [];

    public function notify(Transfer $transfer): void
    {
        $this->notified[] = $transfer;

        if ($this->fails) {
            throw new NotificationFailed('The fake notifier was told to fail.');
        }
    }
}
