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

use App\Domain\Money;
use App\Domain\Port\WalletRepository;
use App\Domain\Wallet;
use Hyperf\DbConnection\Db;

final class DbWalletRepository implements WalletRepository
{
    public function findByUserId(int $userId): ?Wallet
    {
        $row = Db::table('wallets')->where('user_id', $userId)->first();

        if ($row === null) {
            return null;
        }

        return new Wallet(
            (int) $row->id,
            (int) $row->user_id,
            Money::fromCents((int) $row->balance_cents),
        );
    }

    /**
     * The balance is the only thing a wallet can change today.
     */
    public function save(Wallet $wallet): void
    {
        Db::table('wallets')->where('id', $wallet->id)->update([
            'balance_cents' => $wallet->balance()->cents(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
