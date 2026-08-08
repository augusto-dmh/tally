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

use App\Domain\AccountKind;
use App\Domain\LedgerDirection;
use App\Domain\Money;
use App\Domain\Port\Ledger;
use DateTimeImmutable;

final class FakeLedger implements Ledger
{
    /**
     * @var list<array{
     *     journal_id: string,
     *     transfer_id: ?int,
     *     wallet_id: ?int,
     *     account_kind: AccountKind,
     *     direction: LedgerDirection,
     *     amount_cents: int,
     *     created_at: DateTimeImmutable
     * }>
     */
    public array $entries = [];

    public function appendTransferLegs(
        string $journalId,
        int $transferId,
        int $payerWalletId,
        int $payeeWalletId,
        Money $amount,
        DateTimeImmutable $createdAt,
    ): void {
        $cents = $amount->cents();

        $this->entries[] = [
            'journal_id' => $journalId,
            'transfer_id' => $transferId,
            'wallet_id' => $payerWalletId,
            'account_kind' => AccountKind::Wallet,
            'direction' => LedgerDirection::Debit,
            'amount_cents' => $cents,
            'created_at' => $createdAt,
        ];
        $this->entries[] = [
            'journal_id' => $journalId,
            'transfer_id' => $transferId,
            'wallet_id' => $payeeWalletId,
            'account_kind' => AccountKind::Wallet,
            'direction' => LedgerDirection::Credit,
            'amount_cents' => $cents,
            'created_at' => $createdAt,
        ];
    }

    public function appendOpeningLegs(
        string $journalId,
        int $walletId,
        Money $balance,
        DateTimeImmutable $createdAt,
    ): void {
        $cents = $balance->cents();

        $this->entries[] = [
            'journal_id' => $journalId,
            'transfer_id' => null,
            'wallet_id' => $walletId,
            'account_kind' => AccountKind::Wallet,
            'direction' => LedgerDirection::Credit,
            'amount_cents' => $cents,
            'created_at' => $createdAt,
        ];
        $this->entries[] = [
            'journal_id' => $journalId,
            'transfer_id' => null,
            'wallet_id' => null,
            'account_kind' => AccountKind::SystemOpening,
            'direction' => LedgerDirection::Debit,
            'amount_cents' => $cents,
            'created_at' => $createdAt,
        ];
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
        $nets = [];

        foreach ($this->entries as $entry) {
            if ($entry['wallet_id'] === null) {
                continue;
            }

            $walletId = $entry['wallet_id'];
            $nets[$walletId] ??= 0;

            if ($entry['direction'] === LedgerDirection::Credit) {
                $nets[$walletId] += $entry['amount_cents'];
            } else {
                $nets[$walletId] -= $entry['amount_cents'];
            }
        }

        return $nets;
    }

    private function sumByDirection(LedgerDirection $direction): int
    {
        $total = 0;

        foreach ($this->entries as $entry) {
            if ($entry['direction'] === $direction) {
                $total += $entry['amount_cents'];
            }
        }

        return $total;
    }
}
