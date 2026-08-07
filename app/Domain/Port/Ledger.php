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

use App\Domain\Money;
use DateTimeImmutable;

interface Ledger
{
    /**
     * Posts debit(payer) + credit(payee); both account_kind = wallet.
     */
    public function appendTransferLegs(
        string $journalId,
        int $transferId,
        int $payerWalletId,
        int $payeeWalletId,
        Money $amount,
        DateTimeImmutable $createdAt,
    ): void;

    /**
     * Posts credit(wallet) + debit(system_opening). Non-zero only at call site.
     */
    public function appendOpeningLegs(
        string $journalId,
        int $walletId,
        Money $balance,
        DateTimeImmutable $createdAt,
    ): void;

    public function totalCreditsCents(): int;

    public function totalDebitsCents(): int;

    /**
     * @return array<int, int> wallet_id → credits − debits for non-null wallet_id rows
     */
    public function walletNetsCents(): array;
}
