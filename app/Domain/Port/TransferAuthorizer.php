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

use App\Domain\Transfer;

interface TransferAuthorizer
{
    /**
     * Answers false whenever the transfer is not cleared, including when the
     * authorizer itself cannot answer.
     */
    public function authorize(Transfer $transfer): bool;
}
