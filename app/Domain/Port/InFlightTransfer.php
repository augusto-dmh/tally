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

use App\Domain\InFlightTransferParties;

interface InFlightTransfer
{
    public function set(int $payerId, int $payeeId): void;

    public function get(): ?InFlightTransferParties;
}
