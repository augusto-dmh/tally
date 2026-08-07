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
use App\Infrastructure\Persistence\OpeningLedgerBackfill;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;

/**
 * Spec anchors: LEDG-05 — non-zero wallets get opening journals; zero-balance
 * wallets need none; helper is idempotent across migrate/seed invocations.
 *
 * @internal
 * @coversNothing
 */
final class OpeningLedgerBackfillTest extends IntegrationTestCase
{
    public function testLedgerEntriesTableMatchesTheDesignSchema(): void
    {
        $this->assertTrue(Schema::hasTable('ledger_entries'));
        $this->assertTrue(Schema::hasColumns('ledger_entries', [
            'id',
            'journal_id',
            'transfer_id',
            'wallet_id',
            'account_kind',
            'direction',
            'amount_cents',
            'created_at',
        ]));

        $indexedColumns = Db::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME != ?',
            ['ledger_entries', 'PRIMARY']
        );
        $indexed = array_map(static fn ($row) => $row->COLUMN_NAME, $indexedColumns);
        foreach (['journal_id', 'transfer_id', 'wallet_id', 'account_kind'] as $column) {
            $this->assertContains($column, $indexed);
        }

        $foreignColumns = Db::select(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['ledger_entries']
        );
        $fkByColumn = [];
        foreach ($foreignColumns as $row) {
            $fkByColumn[$row->COLUMN_NAME] = $row->REFERENCED_TABLE_NAME;
        }
        $this->assertSame('transfers', $fkByColumn['transfer_id'] ?? null);
        $this->assertSame('wallets', $fkByColumn['wallet_id'] ?? null);
    }

    public function testBackfillCreatesOpeningJournalsForNonZeroWalletsAndSkipsZero(): void
    {
        $aliceId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $brunoId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $mercadoId = $this->insertUser('33333333333', 'merchant', 'Mercado Central', 'mercado@tally.test');

        $aliceWalletId = $this->insertWallet($aliceId, 100000);
        $brunoWalletId = $this->insertWallet($brunoId, 50000);
        $mercadoWalletId = $this->insertWallet($mercadoId, 0);

        (new OpeningLedgerBackfill())->run();

        $this->assertOpeningJournal($aliceWalletId, 100000);
        $this->assertOpeningJournal($brunoWalletId, 50000);
        $this->assertSame(0, $this->legCountForWallet($mercadoWalletId));
        $this->assertSame(0, Db::table('ledger_entries')->whereNull('transfer_id')->where('wallet_id', $mercadoWalletId)->count());
    }

    public function testBackfillIsIdempotentWhenWalletsAlreadyHaveLegs(): void
    {
        $userId = $this->insertUser('11111111111');
        $walletId = $this->insertWallet($userId, 100000);

        $backfill = new OpeningLedgerBackfill();
        $backfill->run();
        $backfill->run();

        $this->assertSame(1, $this->legCountForWallet($walletId));
        $this->assertSame(2, Db::table('ledger_entries')->where('journal_id', $this->openingJournalId($walletId))->count());
    }

    private function assertOpeningJournal(int $walletId, int $balanceCents): void
    {
        $walletLeg = Db::table('ledger_entries')
            ->where('wallet_id', $walletId)
            ->whereNull('transfer_id')
            ->first();

        $this->assertNotNull($walletLeg);
        $this->assertSame(AccountKind::Wallet->value, $walletLeg->account_kind);
        $this->assertSame(LedgerDirection::Credit->value, $walletLeg->direction);
        $this->assertSame($balanceCents, (int) $walletLeg->amount_cents);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $walletLeg->journal_id
        );

        $systemLeg = Db::table('ledger_entries')
            ->where('journal_id', $walletLeg->journal_id)
            ->whereNull('wallet_id')
            ->first();

        $this->assertNotNull($systemLeg);
        $this->assertNull($systemLeg->transfer_id);
        $this->assertSame(AccountKind::SystemOpening->value, $systemLeg->account_kind);
        $this->assertSame(LedgerDirection::Debit->value, $systemLeg->direction);
        $this->assertSame($balanceCents, (int) $systemLeg->amount_cents);

        $net = (int) Db::table('ledger_entries')
            ->where('wallet_id', $walletId)
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount_cents ELSE -amount_cents END) AS net")
            ->value('net');

        $this->assertSame($balanceCents, $net);
    }

    private function legCountForWallet(int $walletId): int
    {
        return (int) Db::table('ledger_entries')->where('wallet_id', $walletId)->count();
    }

    private function openingJournalId(int $walletId): string
    {
        return (string) Db::table('ledger_entries')
            ->where('wallet_id', $walletId)
            ->whereNull('transfer_id')
            ->value('journal_id');
    }
}
