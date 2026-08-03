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

interface TransactionRunner
{
    /**
     * Runs the operation atomically: anything it throws discards every write it made.
     */
    public function run(callable $operation): mixed;
}
