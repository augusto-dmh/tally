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

/**
 * HTTP-ready outcome of a transfer attempt: status, JSON body, and the
 * structured success payload when the money move completed.
 */
final class TransferResult
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $body,
        public readonly ?TransferFundsOutput $output = null,
    ) {
    }
}
