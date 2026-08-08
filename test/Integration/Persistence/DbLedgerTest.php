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

namespace HyperfTest\Integration\Persistence;

use App\Domain\AccountKind;
use App\Domain\LedgerDirection;
use App\Domain\Money;
use App\Domain\Port\Ledger;
use App\Infrastructure\Persistence\DbLedger;
use App\Infrastructure\Persistence\DbTransactionRunner;
use DateTimeImmutable;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;
use RuntimeException;

/**
 * Spec anchors: LEDG-01 (balanced transfer legs), LEDG-02 (append-only inserts),
 * LEDG-04 (wallet nets from journal match projection math).
 *
 * @internal
 * @coversNothing
 */
final class DbLedgerTest extends IntegrationTestCase
{
    public function testAppendTransferLegsPersistsABalancedDebitCreditPair(): void
    {
        [$payerWalletId, $payeeWalletId, $transferId] = $this->seedTransferPair(100000, 50000, 15050);
        $ledger = new DbLedger();
        $createdAt = new DateTimeImmutable('2026-08-07 12:00:00');

        $ledger->appendTransferLegs(
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            $transferId,
            $payerWalletId,
            $payeeWalletId,
            Money::fromCents(15050),
            $createdAt,
        );

        $rows = Db::table('ledger_entries')->where('transfer_id', $transferId)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $debit = $rows[0];
        $credit = $rows[1];
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $debit->journal_id);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $credit->journal_id);
        $this->assertSame($payerWalletId, (int) $debit->wallet_id);
        $this->assertSame($payeeWalletId, (int) $credit->wallet_id);
        $this->assertSame(AccountKind::Wallet->value, $debit->account_kind);
        $this->assertSame(AccountKind::Wallet->value, $credit->account_kind);
        $this->assertSame(LedgerDirection::Debit->value, $debit->direction);
        $this->assertSame(LedgerDirection::Credit->value, $credit->direction);
        $this->assertSame(15050, (int) $debit->amount_cents);
        $this->assertSame(15050, (int) $credit->amount_cents);
        $this->assertSame('2026-08-07 12:00:00', $debit->created_at);
        $this->assertSame('2026-08-07 12:00:00', $credit->created_at);

        $this->assertSame(15050, $ledger->totalCreditsCents());
        $this->assertSame(15050, $ledger->totalDebitsCents());
        $nets = $ledger->walletNetsCents();
        $this->assertSame(-15050, $nets[$payerWalletId]);
        $this->assertSame(15050, $nets[$payeeWalletId]);
    }

    public function testAppendOpeningLegsPersistsCreditWalletAndDebitSystemOpening(): void
    {
        $userId = $this->insertUser('11111111111');
        $walletId = $this->insertWallet($userId, 100000);
        $ledger = new DbLedger();

        $ledger->appendOpeningLegs(
            'ffffffff-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            $walletId,
            Money::fromCents(100000),
            new DateTimeImmutable('2026-08-07 12:00:00'),
        );

        $walletLeg = Db::table('ledger_entries')->where('wallet_id', $walletId)->first();
        $this->assertNotNull($walletLeg);
        $this->assertNull($walletLeg->transfer_id);
        $this->assertSame(AccountKind::Wallet->value, $walletLeg->account_kind);
        $this->assertSame(LedgerDirection::Credit->value, $walletLeg->direction);
        $this->assertSame(100000, (int) $walletLeg->amount_cents);

        $systemLeg = Db::table('ledger_entries')
            ->where('journal_id', $walletLeg->journal_id)
            ->whereNull('wallet_id')
            ->first();
        $this->assertNotNull($systemLeg);
        $this->assertSame(AccountKind::SystemOpening->value, $systemLeg->account_kind);
        $this->assertSame(LedgerDirection::Debit->value, $systemLeg->direction);
        $this->assertSame(100000, (int) $systemLeg->amount_cents);

        $this->assertSame(100000, $ledger->totalCreditsCents());
        $this->assertSame(100000, $ledger->totalDebitsCents());
        $this->assertSame([$walletId => 100000], $ledger->walletNetsCents());
    }

    public function testAppendsAccumulateAndParticipateInAnOpenTransaction(): void
    {
        [$payerWalletId, $payeeWalletId, $transferId] = $this->seedTransferPair(100000, 50000, 100);
        $ledger = new DbLedger();
        $runner = new DbTransactionRunner();
        $createdAt = new DateTimeImmutable('2026-08-07 12:00:00');

        $rolledBack = null;
        try {
            $runner->run(function () use ($ledger, $transferId, $payerWalletId, $payeeWalletId, $createdAt): void {
                $ledger->appendTransferLegs('journal-1', $transferId, $payerWalletId, $payeeWalletId, Money::fromCents(100), $createdAt);
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $rolledBack = $exception;
        }

        $this->assertNotNull($rolledBack);
        $this->assertSame(0, Db::table('ledger_entries')->count());

        $runner->run(function () use ($ledger, $transferId, $payerWalletId, $payeeWalletId, $createdAt): void {
            $ledger->appendTransferLegs('journal-1', $transferId, $payerWalletId, $payeeWalletId, Money::fromCents(100), $createdAt);
        });

        $userId = $this->insertUser('33333333333', 'merchant', 'Mercado Central', 'mercado@tally.test');
        $openingWalletId = $this->insertWallet($userId, 50000);
        $ledger->appendOpeningLegs('journal-2', $openingWalletId, Money::fromCents(50000), $createdAt);

        $this->assertSame(50100, $ledger->totalCreditsCents());
        $this->assertSame(50100, $ledger->totalDebitsCents());
        $this->assertSame(4, Db::table('ledger_entries')->count());

        $nets = $ledger->walletNetsCents();
        $this->assertSame(-100, $nets[$payerWalletId]);
        $this->assertSame(100, $nets[$payeeWalletId]);
        $this->assertSame(50000, $nets[$openingWalletId]);
    }

    public function testLedgerPortIsBoundToDbLedger(): void
    {
        $resolved = ApplicationContext::getContainer()->get(Ledger::class);

        $this->assertInstanceOf(DbLedger::class, $resolved);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function seedTransferPair(int $payerBalance, int $payeeBalance, int $amountCents): array
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerId, $payerBalance);
        $payeeWalletId = $this->insertWallet($payeeId, $payeeBalance);

        $transferId = (int) Db::table('transfers')->insertGetId([
            'payer_wallet_id' => $payerWalletId,
            'payee_wallet_id' => $payeeWalletId,
            'amount_cents' => $amountCents,
            'created_at' => '2026-08-07 12:00:00',
        ]);

        return [$payerWalletId, $payeeWalletId, $transferId];
    }
}
