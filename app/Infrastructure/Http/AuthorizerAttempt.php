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

use App\Domain\Transfer;

interface AuthorizerAttempt
{
    /**
     * @return bool true clear, false explicit decline
     * @throws AuthorizerUnavailable when this provider cannot answer
     */
    public function attempt(Transfer $transfer): bool;
}
