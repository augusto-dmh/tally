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

use App\Domain\Port\TransactionRunner;
use Throwable;

/**
 * Runs the operation without any rollback of its own — the database adapter
 * owns that. What it records is the boundary crossing: `thrown` holds whatever
 * escaped the operation, which is exactly what makes a real transaction roll
 * back.
 */
final class FakeTransactionRunner implements TransactionRunner
{
    public int $runs = 0;

    public ?Throwable $thrown = null;

    public function run(callable $operation): mixed
    {
        ++$this->runs;

        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->thrown = $exception;

            throw $exception;
        }
    }
}
