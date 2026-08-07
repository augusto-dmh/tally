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

use App\Domain\Wallet;

interface WalletRepository
{
    public function findByUserId(int $userId): ?Wallet;

    /**
     * SELECT … FOR UPDATE. Call only inside a TransactionRunner transaction.
     */
    public function findByUserIdForUpdate(int $userId): ?Wallet;

    public function save(Wallet $wallet): void;
}
