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
 * Counts from one DrainOutbox pass over a claimed batch.
 */
final class DrainOutboxResult
{
    public function __construct(
        public readonly int $processed,
        public readonly int $done,
        public readonly int $failed,
        public readonly int $dead,
    ) {
    }
}
