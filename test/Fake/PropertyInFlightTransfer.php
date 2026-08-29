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

use App\Domain\InFlightTransferParties;
use App\Domain\Port\InFlightTransfer;

final class PropertyInFlightTransfer implements InFlightTransfer
{
    private ?InFlightTransferParties $current = null;

    public function set(int $payerId, int $payeeId): void
    {
        $this->current = new InFlightTransferParties($payerId, $payeeId);
    }

    public function get(): ?InFlightTransferParties
    {
        return $this->current;
    }
}
