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

use App\Domain\Port\TransferAuthorizer;
use App\Domain\Transfer;

final class FakeTransferAuthorizer implements TransferAuthorizer
{
    public bool $authorizes = true;

    /** @var array<int, Transfer> */
    public array $authorized = [];

    public function authorize(Transfer $transfer): bool
    {
        $this->authorized[] = $transfer;

        return $this->authorizes;
    }
}
