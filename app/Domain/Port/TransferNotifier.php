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

namespace App\Domain\Port;

use App\Domain\Exception\NotificationFailed;
use App\Domain\Transfer;

interface TransferNotifier
{
    /**
     * @throws NotificationFailed when the payee could not be notified
     */
    public function notify(Transfer $transfer): void;
}
