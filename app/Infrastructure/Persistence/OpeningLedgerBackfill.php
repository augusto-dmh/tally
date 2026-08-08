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

use App\Domain\AccountKind;
use App\Domain\LedgerDirection;
use Hyperf\DbConnection\Db;

/**
 * Idempotent opening journals for wallets whose balance is not yet journalled.
 * Invoked from the ledger_entries migration and again after the demo seeder.
 */
final class OpeningLedgerBackfill
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $wallets = Db::table('wallets')->get(['id', 'balance_cents']);

        foreach ($wallets as $wallet) {
            $walletId = (int) $wallet->id;
            $balanceCents = (int) $wallet->balance_cents;

            if ($balanceCents === 0) {
                continue;
            }

            $alreadyHasLegs = Db::table('ledger_entries')
                ->where('wallet_id', $walletId)
                ->exists();

            if ($alreadyHasLegs) {
                continue;
            }

            $journalId = $this->uuidV4();

            Db::table('ledger_entries')->insert([
                [
                    'journal_id' => $journalId,
                    'transfer_id' => null,
                    'wallet_id' => $walletId,
                    'account_kind' => AccountKind::Wallet->value,
                    'direction' => LedgerDirection::Credit->value,
                    'amount_cents' => $balanceCents,
                    'created_at' => $now,
                ],
                [
                    'journal_id' => $journalId,
                    'transfer_id' => null,
                    'wallet_id' => null,
                    'account_kind' => AccountKind::SystemOpening->value,
                    'direction' => LedgerDirection::Debit->value,
                    'amount_cents' => $balanceCents,
                    'created_at' => $now,
                ],
            ]);
        }
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
