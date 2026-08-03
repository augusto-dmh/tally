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

use App\Domain\Port\TransferRepository;
use App\Domain\Transfer;
use Hyperf\DbConnection\Db;

final class DbTransferRepository implements TransferRepository
{
    public function add(Transfer $transfer): Transfer
    {
        $id = (int) Db::table('transfers')->insertGetId([
            'payer_wallet_id' => $transfer->payerWalletId,
            'payee_wallet_id' => $transfer->payeeWalletId,
            'amount_cents' => $transfer->amount->cents(),
            'created_at' => $transfer->createdAt->format('Y-m-d H:i:s'),
        ]);

        return new Transfer(
            $id,
            $transfer->payerWalletId,
            $transfer->payeeWalletId,
            $transfer->amount,
            $transfer->createdAt,
        );
    }
}
