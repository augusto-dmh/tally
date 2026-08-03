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

namespace App\Infrastructure\Persistence;

use App\Domain\Port\TransactionRunner;
use Hyperf\DbConnection\Db;

final class DbTransactionRunner implements TransactionRunner
{
    /**
     * The operation takes no arguments, so the connection Hyperf hands the
     * closure is dropped: the domain never sees it.
     */
    public function run(callable $operation): mixed
    {
        return Db::transaction(static fn (): mixed => $operation());
    }
}
