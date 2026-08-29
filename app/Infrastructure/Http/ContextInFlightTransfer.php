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

namespace App\Infrastructure\Http;

use App\Domain\InFlightTransferParties;
use App\Domain\Port\InFlightTransfer;
use Hyperf\Context\Context;

final class ContextInFlightTransfer implements InFlightTransfer
{
    public function set(int $payerId, int $payeeId): void
    {
        Context::set(InFlightTransferParties::class, new InFlightTransferParties($payerId, $payeeId));
    }

    public function get(): ?InFlightTransferParties
    {
        return Context::get(InFlightTransferParties::class);
    }
}
