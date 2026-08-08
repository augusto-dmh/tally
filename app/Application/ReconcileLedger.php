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

namespace App\Application;

use App\Domain\Port\Ledger;
use App\Domain\Port\WalletRepository;

/**
 * Read-only compare of ledger aggregates to wallet projections. Never writes.
 */
final class ReconcileLedger
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    public function execute(): ReconcileLedgerResult
    {
        $violations = [];

        $credits = $this->ledger->totalCreditsCents();
        $debits = $this->ledger->totalDebitsCents();
        if ($credits !== $debits) {
            $violations[] = sprintf(
                'global_imbalance: credits=%d debits=%d',
                $credits,
                $debits,
            );
        }

        $balances = $this->walletRepository->listBalanceCentsByWalletId();
        $nets = $this->ledger->walletNetsCents();
        $walletIds = array_unique([...array_keys($balances), ...array_keys($nets)]);

        foreach ($walletIds as $walletId) {
            $balance = $balances[$walletId] ?? 0;
            $net = $nets[$walletId] ?? 0;
            if ($balance !== $net) {
                $violations[] = sprintf(
                    'wallet_projection_drift: wallet_id=%d balance_cents=%d ledger_net=%d',
                    $walletId,
                    $balance,
                    $net,
                );
            }
        }

        return new ReconcileLedgerResult($violations === [], $violations);
    }
}
