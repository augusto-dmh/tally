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

namespace HyperfTest\Unit\Application;

use App\Application\ReconcileLedger;
use App\Domain\AccountKind;
use App\Domain\LedgerDirection;
use App\Domain\Money;
use App\Domain\Wallet;
use DateTimeImmutable;
use HyperfTest\Fake\FakeLedger;
use HyperfTest\Fake\FakeWalletRepository;
use PHPUnit\Framework\TestCase;

/**
 * Spec anchors: LEDG-06 (imbalance / projection drift → not ok, no writes),
 * LEDG-06b (clean totals + matching nets → ok).
 *
 * @internal
 * @coversNothing
 */
final class ReconcileLedgerTest extends TestCase
{
    private FakeLedger $ledger;

    private FakeWalletRepository $wallets;

    private ReconcileLedger $reconcile;

    protected function setUp(): void
    {
        $this->ledger = new FakeLedger();
        $this->wallets = new FakeWalletRepository();
        $this->reconcile = new ReconcileLedger($this->ledger, $this->wallets);
    }

    /** LEDG-06b: balanced ledger and matching projections → ok. */
    public function testItReportsOkWhenCreditsEqualDebitsAndProjectionsMatch(): void
    {
        $this->wallets->save(new Wallet(11, 1, Money::fromCents(10000)));
        $this->wallets->save(new Wallet(22, 2, Money::fromCents(5000)));
        $this->ledger->appendOpeningLegs(
            '11111111-1111-4111-8111-111111111111',
            11,
            Money::fromCents(10000),
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $this->ledger->appendOpeningLegs(
            '22222222-2222-4222-8222-222222222222',
            22,
            Money::fromCents(5000),
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );

        $result = $this->reconcile->execute();

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->violations);
        $this->assertSame(15000, $this->ledger->totalCreditsCents());
        $this->assertSame(15000, $this->ledger->totalDebitsCents());
        $this->assertSame(10000, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(5000, $this->wallets->walletsByUserId[2]->balance()->cents());
    }

    /** LEDG-06b: zero-balance wallet with no legs is fine (both sides 0). */
    public function testItReportsOkForZeroBalanceWalletWithNoLegs(): void
    {
        $this->wallets->save(new Wallet(11, 1, Money::fromCents(0)));

        $result = $this->reconcile->execute();

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->violations);
    }

    /** LEDG-06: projection drift → violation; ledger entries are not mutated. */
    public function testItReportsViolationWhenWalletBalanceDiffersFromLedgerNet(): void
    {
        $this->wallets->save(new Wallet(11, 1, Money::fromCents(9999)));
        $this->ledger->appendOpeningLegs(
            '11111111-1111-4111-8111-111111111111',
            11,
            Money::fromCents(10000),
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $entriesBefore = $this->ledger->entries;

        $result = $this->reconcile->execute();

        $this->assertFalse($result->ok);
        $this->assertNotSame([], $result->violations);
        $this->assertStringContainsString('11', $result->violations[0]);
        $this->assertSame($entriesBefore, $this->ledger->entries);
        $this->assertSame(9999, $this->wallets->walletsByUserId[1]->balance()->cents());
    }

    /** LEDG-06: global credits ≠ debits → violation; no writes. */
    public function testItReportsViolationWhenGlobalCreditsDoNotEqualDebits(): void
    {
        $this->wallets->save(new Wallet(11, 1, Money::fromCents(10000)));
        $this->ledger->entries[] = [
            'journal_id' => '33333333-3333-4333-8333-333333333333',
            'transfer_id' => null,
            'wallet_id' => 11,
            'account_kind' => AccountKind::Wallet,
            'direction' => LedgerDirection::Credit,
            'amount_cents' => 10000,
            'created_at' => new DateTimeImmutable('2026-01-01 00:00:00'),
        ];
        $entriesBefore = $this->ledger->entries;

        $result = $this->reconcile->execute();

        $this->assertFalse($result->ok);
        $this->assertNotSame([], $result->violations);
        $this->assertSame(10000, $this->ledger->totalCreditsCents());
        $this->assertSame(0, $this->ledger->totalDebitsCents());
        $this->assertSame($entriesBefore, $this->ledger->entries);
        $this->assertSame(10000, $this->wallets->walletsByUserId[1]->balance()->cents());
    }
}
