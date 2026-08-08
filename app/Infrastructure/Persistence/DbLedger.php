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
use App\Domain\Money;
use App\Domain\Port\Ledger;
use DateTimeImmutable;
use Hyperf\DbConnection\Db;

final class DbLedger implements Ledger
{
    public function appendTransferLegs(
        string $journalId,
        int $transferId,
        int $payerWalletId,
        int $payeeWalletId,
        Money $amount,
        DateTimeImmutable $createdAt,
    ): void {
        $cents = $amount->cents();
        $createdAtSql = $createdAt->format('Y-m-d H:i:s');

        Db::table('ledger_entries')->insert([
            [
                'journal_id' => $journalId,
                'transfer_id' => $transferId,
                'wallet_id' => $payerWalletId,
                'account_kind' => AccountKind::Wallet->value,
                'direction' => LedgerDirection::Debit->value,
                'amount_cents' => $cents,
                'created_at' => $createdAtSql,
            ],
            [
                'journal_id' => $journalId,
                'transfer_id' => $transferId,
                'wallet_id' => $payeeWalletId,
                'account_kind' => AccountKind::Wallet->value,
                'direction' => LedgerDirection::Credit->value,
                'amount_cents' => $cents,
                'created_at' => $createdAtSql,
            ],
        ]);
    }

    public function appendOpeningLegs(
        string $journalId,
        int $walletId,
        Money $balance,
        DateTimeImmutable $createdAt,
    ): void {
        $cents = $balance->cents();
        $createdAtSql = $createdAt->format('Y-m-d H:i:s');

        Db::table('ledger_entries')->insert([
            [
                'journal_id' => $journalId,
                'transfer_id' => null,
                'wallet_id' => $walletId,
                'account_kind' => AccountKind::Wallet->value,
                'direction' => LedgerDirection::Credit->value,
                'amount_cents' => $cents,
                'created_at' => $createdAtSql,
            ],
            [
                'journal_id' => $journalId,
                'transfer_id' => null,
                'wallet_id' => null,
                'account_kind' => AccountKind::SystemOpening->value,
                'direction' => LedgerDirection::Debit->value,
                'amount_cents' => $cents,
                'created_at' => $createdAtSql,
            ],
        ]);
    }

    public function totalCreditsCents(): int
    {
        return $this->sumByDirection(LedgerDirection::Credit);
    }

    public function totalDebitsCents(): int
    {
        return $this->sumByDirection(LedgerDirection::Debit);
    }

    public function walletNetsCents(): array
    {
        $credit = LedgerDirection::Credit->value;
        $rows = Db::table('ledger_entries')
            ->whereNotNull('wallet_id')
            ->selectRaw(
                "wallet_id, SUM(CASE WHEN direction = '{$credit}' THEN amount_cents ELSE -amount_cents END) AS net"
            )
            ->groupBy('wallet_id')
            ->get();

        $nets = [];
        foreach ($rows as $row) {
            $nets[(int) $row->wallet_id] = (int) $row->net;
        }

        return $nets;
    }

    private function sumByDirection(LedgerDirection $direction): int
    {
        return (int) Db::table('ledger_entries')
            ->where('direction', $direction->value)
            ->sum('amount_cents');
    }
}
